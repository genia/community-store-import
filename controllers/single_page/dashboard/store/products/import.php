<?php
namespace Concrete\Package\CommunityStoreImport\Controller\SinglePage\Dashboard\Store\Products;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\File\File;
use Concrete\Core\File\Importer;
use Concrete\Core\Support\Facade\Log;
use Concrete\Core\Config\Repository\Repository as Config;
use Symfony\Component\HttpFoundation\JsonResponse;
use Exception;

use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Entity\Attribute\Key\StoreProductKey;
use Concrete\Package\CommunityStore\Src\CommunityStore\Multilingual\Translation;
use Concrete\Core\Multilingual\Page\Section\Section;
use Concrete\Core\Page\Page;
use Concrete\Package\CommunityStore\Attribute\ProductKey;


class Import extends DashboardPageController
{
    public $helpers = array('form', 'concrete/asset_library', 'json');
    private $attributes = array();
    private $productAttributes = array(); // pAttr_ prefixed columns for product attributes

    public function view()
    {
        $this->loadFormAssets();
        $this->set('pageTitle', t('Product Import'));
    }

    /**
     * Handle image file uploads via drag-and-drop
     */
    public function upload_images()
    {
        if (!$this->token->validate('upload_images')) {
            return new JsonResponse([
                'success' => false,
                'error' => t('Invalid security token')
            ], 400);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return new JsonResponse([
                'success' => false,
                'error' => t('File upload failed')
            ], 400);
        }

        $file = $_FILES['file'];
        
        // Validate file type
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            return new JsonResponse([
                'success' => false,
                'error' => t('Invalid file type. Only image files are allowed.')
            ], 400);
        }

        // Check if file with same filename already exists
        $filename = basename($file['name']);
        $existingFile = $this->findExistingFile($filename);
        if ($existingFile) {
            return new JsonResponse([
                'success' => true,
                'skipped' => true,
                'fileID' => $existingFile->getFileID(),
                'filename' => $filename,
                'message' => t('File already exists and was skipped')
            ]);
        }

        // Upload the file
        try {
            $importer = $this->app->make(Importer::class);
            $fv = $importer->import($file['tmp_name'], $filename, null);

            if ($fv instanceof \Concrete\Core\Entity\File\Version) {
                $fileObj = $fv->getFile();
                return new JsonResponse([
                    'success' => true,
                    'skipped' => false,
                    'fileID' => $fileObj->getFileID(),
                    'filename' => $filename,
                    'message' => t('File uploaded successfully')
                ]);
            }
        } catch (Exception $e) {
            Log::addWarning('Failed to upload image: ' . $filename . ' - ' . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'error' => t('Failed to upload file: %s', $e->getMessage())
            ], 500);
        }

        return new JsonResponse([
            'success' => false,
            'error' => t('File upload failed')
        ], 500);
    }

    public function loadFormAssets()
    {
        $this->requireAsset('core/file-manager');
        $this->requireAsset('core/sitemap');
        $this->requireAsset('css', 'select2');
        $this->requireAsset('javascript', 'select2');
        $this->set('concrete_asset_library', $this->app->make('helper/concrete/asset_library'));
        $this->set('form', $this->app->make('helper/form'));
    }

    public function run()
    {
        $this->saveSettings();

        $config = $this->app->make(Config::class);
        $MAX_TIME = $config->get('community_store_import.max_execution_time');
        $MAX_EXECUTION_TIME = ini_get('max_execution_time');
        $MAX_INPUT_TIME = ini_get('max_input_time');
        ini_set('max_execution_time', $MAX_TIME);
        ini_set('max_input_time', $MAX_TIME);
        ini_set('auto_detect_line_endings', TRUE);

        $data = $this->post();
        $handle = null;
        $isGoogleSheets = false;

        // Check if Google Sheets URL is provided
        if (!empty($data['google_sheets_url'])) {
            $csvContent = $this->fetchGoogleSheetsCsv($data['google_sheets_url']);
            if ($csvContent === false) {
                $this->error->add(t("Failed to fetch data from Google Sheets. Please ensure the sheet is publicly viewable."));
                return;
            }
            // Create a temporary file handle from the CSV content
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $csvContent);
            rewind($handle);
            $isGoogleSheets = true;
        } else {
            // Use uploaded file as before
            $f = File::getByID($config->get('community_store_import.import_file'));
            if (!$f || $f->isError()) {
                $this->error->add(t("Import file not found. Please upload a CSV file or provide a Google Sheets URL."));
                return;
            }
            $fname = $_SERVER['DOCUMENT_ROOT'] . $f->getApprovedVersion()->getRelativePath();

            if (!file_exists($fname) || !is_readable($fname)) {
                $this->error->add(t("Import file not found or is not readable."));
                return;
            }

            if (!$handle = @fopen($fname, 'r')) {
                $this->error->add(t('Cannot open file %s.', $fname));
                return;
            }
        }

        $delim = $config->get('community_store_import.csv.delimiter');
        $delim = ($delim === '\t') ? "\t" : $delim;

        $enclosure = $config->get('community_store_import.csv.enclosure');
        $line_length = $config->get('community_store_import.csv.line_length');

        // Get headings
        $csv = fgetcsv($handle, $line_length, $delim, $enclosure);
        $headings = array_map('strtolower', $csv);

        if ($this->isValid($headings)) {
            $this->error->add(t("Required data missing."));
            return;
        }

        // Get attribute headings (attr_ prefix - legacy)
        foreach ($headings as $heading) {
            if (preg_match('/^attr_/', $heading)) {
                $this->attributes[] = $heading;
            }
        }

        // Get product attribute headings (pAttr_ prefix - new format)
        // e.g., pAttr_Type, pAttr_Metal, pAttr_Stone
        foreach ($headings as $heading) {
            if (preg_match('/^pattr_/i', $heading)) {
                // Extract attribute handle from column name (e.g., pAttr_Metal -> metal)
                $attrHandle = strtolower(preg_replace('/^pattr_/i', '', $heading));
                $this->productAttributes[$heading] = $attrHandle;
            }
        }

        // Detect multilingual columns (e.g., "pname - ru", "pdesc - ru")
        $multilingualColumns = [];
        foreach ($headings as $heading) {
            // Match patterns like "pname - ru", "pdesc - ru", "pdetail - ru"
            if (preg_match('/^(pname|pdesc|pdetail)\s*-\s*([a-z]{2})$/i', trim($heading), $matches)) {
                $field = strtolower($matches[1]);
                $locale = strtolower($matches[2]);
                if (!isset($multilingualColumns[$locale])) {
                    $multilingualColumns[$locale] = [];
                }
                $multilingualColumns[$locale][$field] = $heading;
            }
        }

        $updated = 0;
        $added = 0;
        $imagesProcessed = 0;
        $imagesFailed = 0;
        $pagesCreated = 0;
        $multilingualProcessed = 0;
        $attributesProcessed = 0;

        while (($csv = fgetcsv($handle, $line_length, $delim, $enclosure)) !== FALSE) {
            if (count($csv) === 1) {
                continue;
            }

            // Make associative arrray
            $row = array_combine($headings, $csv);

            $p = Product::getBySKU($row['psku']);
            
            $imageProcessed = false;
            if ($p instanceof Product) {
                $oldImageId = $p->getImageId();
                $this->update($p, $row);
                $updated++;
                // Check if image was updated
                if (isset($row['imagefile']) && !empty($row['imagefile'])) {
                    $newImageId = $p->getImageId();
                    $imageProcessed = ($newImageId && $newImageId != $oldImageId);
                }
            } else {
                $p = $this->add($row);
                $added++;
                // Check if image was set for new product
                if (isset($row['imagefile']) && !empty($row['imagefile'])) {
                    $imageProcessed = (bool)$p->getImageId();
                }
            }

            // Count images
            if (isset($row['imagefile']) && !empty($row['imagefile'])) {
                if ($imageProcessed) {
                    $imagesProcessed++;
                } else {
                    $imagesFailed++;
                }
            }

            // Generate product page if it doesn't exist
            if ($this->generateProductPage($p)) {
                $pagesCreated++;
            }

            // Process multilingual translations
            if (!empty($multilingualColumns)) {
                if ($this->processMultilingualTranslations($p, $row, $multilingualColumns)) {
                    $multilingualProcessed++;
                }
            }

            // Process product attributes (pAttr_ columns)
            if (!empty($this->productAttributes)) {
                if ($this->processProductAttributes($p, $row)) {
                    $attributesProcessed++;
                }
            }

            // @TODO: dispatch events - see Products::save()
        }

        // Close file handle
        if ($handle) {
            @fclose($handle);
        }

        $successMsg = "Import completed: $added products added, $updated products updated.";
        if ($imagesProcessed > 0 || $imagesFailed > 0) {
            $successMsg .= " Images processed: $imagesProcessed";
            if ($imagesFailed > 0) {
                $successMsg .= ", failed: $imagesFailed";
            }
        }
        if ($pagesCreated > 0) {
            $successMsg .= " Product pages created: $pagesCreated";
        }
        if ($multilingualProcessed > 0) {
            $successMsg .= " Products with multilingual content: $multilingualProcessed";
        }
        if ($attributesProcessed > 0) {
            $successMsg .= " Products with attributes: $attributesProcessed";
        }
        
        $this->set('success', $this->get('success') . $successMsg);
        Log::addInfo($this->get('success'));

        ini_set('auto_detect_line_endings', FALSE);
        ini_set('max_execution_time', $MAX_EXECUTION_TIME);
        ini_set('max_input_time', $MAX_INPUT_TIME);
    }

    /**
     * Fetch CSV data from a public Google Sheets URL
     * @param string $url Google Sheets URL
     * @return string|false CSV content on success, false on failure
     */
    private function fetchGoogleSheetsCsv($url)
    {
        // Extract spreadsheet ID from URL
        // URL format: https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit?usp=sharing
        // Or: https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/
        if (!preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url, $matches)) {
            Log::addWarning('Invalid Google Sheets URL format: ' . $url);
            return false;
        }

        $spreadsheetId = $matches[1];
        
        // Convert to CSV export URL
        // Default sheet (gid=0) or you can specify a specific sheet
        $csvUrl = 'https://docs.google.com/spreadsheets/d/' . $spreadsheetId . '/export?format=csv';
        
        // Try to fetch the CSV
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (compatible; ConcreteCMS Import)',
                    'Accept: text/csv'
                ],
                'timeout' => 30,
                'follow_location' => 1,
                'max_redirects' => 5
            ]
        ]);

        $csvContent = @file_get_contents($csvUrl, false, $context);
        
        if ($csvContent === false) {
            Log::addWarning('Failed to fetch Google Sheets CSV from: ' . $csvUrl);
            return false;
        }

        // Check if we got an error page (Google Sheets sometimes returns HTML error pages)
        if (stripos($csvContent, '<html') !== false || stripos($csvContent, '<!doctype') !== false) {
            Log::addWarning('Google Sheets returned HTML instead of CSV. Sheet may not be publicly accessible.');
            return false;
        }

        return $csvContent;
    }

    private function setAttributes($product, $row)
    {
        // Community Store v1.x+
        if (class_exists('\Concrete\Package\CommunityStore\Src\Attribute\Key\StoreProductKey')) {
            foreach ($this->attributes as $attr) {
                $ak = preg_replace('/^attr_/', '', $attr);
                if (StoreProductKey::getByHandle($ak)) {
                    $product->setAttribute($ak, $row[$attr]);
                }
            }
        // Community Store v2.0.5+
        } elseif (class_exists('\Concrete\Package\CommunityStore\Attribute\Category\ProductCategory')) {
            $productCategory = $this->app->make('Concrete\Package\CommunityStore\Attribute\Category\ProductCategory');
            $aks = $productCategory->getList();
            foreach ($aks as $ak) {
                $product->setAttribute($ak, $row['attr_'.$ak->getAttributeKeyHandle()]);
            }
        }
    }

    /**
     * Process product attributes from pAttr_ prefixed columns
     * Handles multi-value select attributes with comma-separated values
     * Values are trimmed and capitalized (e.g., "gold, silver" -> ["Gold", "Silver"])
     * 
     * @param Product $product
     * @param array $row CSV row data
     * @return bool True if any attributes were processed
     */
    private function processProductAttributes($product, $row)
    {
        $processed = false;
        
        try {
            // Get product attribute category
            $productCategory = $this->app->make('Concrete\Package\CommunityStore\Attribute\Category\ProductCategory');
            
            foreach ($this->productAttributes as $columnName => $attrHandle) {
                // Check if column exists in row and has a value
                if (!isset($row[$columnName]) || trim($row[$columnName]) === '') {
                    continue;
                }
                
                // Get the attribute key
                $ak = $productCategory->getAttributeKeyByHandle($attrHandle);
                if (!$ak) {
                    Log::addWarning("Product attribute not found: {$attrHandle}");
                    continue;
                }
                
                // Get the raw value from CSV
                $rawValue = $row[$columnName];
                
                // Parse comma-separated values, trim and capitalize each
                $values = array_map(function($val) {
                    $val = trim($val);
                    // Capitalize first letter of each word
                    return ucwords(strtolower($val));
                }, explode(',', $rawValue));
                
                // Remove empty values
                $values = array_filter($values, function($val) {
                    return $val !== '';
                });
                
                if (empty($values)) {
                    continue;
                }
                
                // Get attribute type to determine how to set the value
                $attrType = $ak->getAttributeType()->getAttributeTypeHandle();
                
                if ($attrType === 'select') {
                    // For select attributes, we need to pass the option values
                    // The attribute controller will handle finding/creating options
                    $this->setSelectAttributeValue($product, $ak, $values);
                } else {
                    // For other attribute types, just set the first value as a string
                    $product->setAttribute($attrHandle, implode(', ', $values));
                }
                
                $processed = true;
            }
            
            // Save the product to persist attribute changes
            if ($processed) {
                $product->save();
            }
            
        } catch (Exception $e) {
            Log::addWarning('Error processing product attributes: ' . $e->getMessage());
        }
        
        return $processed;
    }

    /**
     * Set a select attribute value on a product
     * Handles multi-value select attributes by finding or creating option values
     * 
     * @param Product $product
     * @param mixed $ak Attribute key
     * @param array $values Array of option values to set
     */
    private function setSelectAttributeValue($product, $ak, $values)
    {
        try {
            $em = $this->app->make('Doctrine\ORM\EntityManager');
            
            // Get the select attribute settings to find/create options
            $controller = $ak->getController();
            $akSettings = $ak->getAttributeKeySettings();
            
            if (!$akSettings) {
                Log::addWarning("No settings found for select attribute: " . $ak->getAttributeKeyHandle());
                return;
            }
            
            // Get the option list
            $optionList = $akSettings->getOptionList();
            if (!$optionList) {
                Log::addWarning("No option list found for select attribute: " . $ak->getAttributeKeyHandle());
                return;
            }
            
            $selectedOptions = [];
            $existingOptions = $optionList->getOptions();
            
            foreach ($values as $value) {
                $foundOption = null;
                
                // Look for existing option with matching value (case-insensitive)
                foreach ($existingOptions as $option) {
                    if (strcasecmp($option->getSelectAttributeOptionValue(), $value) === 0) {
                        $foundOption = $option;
                        break;
                    }
                }
                
                // If option doesn't exist, create it
                if (!$foundOption) {
                    $foundOption = new \Concrete\Core\Entity\Attribute\Value\Value\SelectValueOption();
                    $foundOption->setSelectAttributeOptionValue($value);
                    $foundOption->setOptionList($optionList);
                    $foundOption->setDisplayOrder(count($existingOptions) + count($selectedOptions));
                    $em->persist($foundOption);
                    
                    Log::addInfo("Created new select option '{$value}' for attribute: " . $ak->getAttributeKeyHandle());
                }
                
                $selectedOptions[] = $foundOption;
            }
            
            // Flush to ensure new options are saved
            $em->flush();
            
            // Set the attribute value on the product
            // For multi-select, we pass an array of option objects
            if (!empty($selectedOptions)) {
                $product->setAttribute($ak->getAttributeKeyHandle(), $selectedOptions);
            }
            
        } catch (Exception $e) {
            Log::addWarning('Error setting select attribute value: ' . $e->getMessage());
        }
    }

    private function add($row)
    {
        $data = array(
            'pSKU' => $row['psku'],
            'pName' => $row['pname'],
            'pDesc' => trim($row['pdesc']),
            'pDetail' => trim($row['pdetail']),
            'pCustomerPrice' => $row['pcustomerprice'],
            'pFeatured' => $row['pfeatured'],
            'pQty' => $row['pqty'],
            'pNoQty' => $row['pnoqty'],
            'pTaxable' => $row['ptaxable'],
            'pActive' => $row['pactive'],
            'pShippable' => $row['pshippable'],
            'pCreateUserAccount' => $row['pcreateuseraccount'],
            'pAutoCheckout' => $row['pautocheckout'],
            'pExclusive' => $row['pexclusive'],

            'pPrice' => $row['pprice'],
            'pSalePrice' => $row['psaleprice'],
            'pPriceMaximum' => $row['ppricemaximum'],
            'pPriceMinimum' => $row['ppriceminimum'],
            'pPriceSuggestions' => $row['ppricesuggestions'],
            'pQtyUnlim' => $row['pqtyunlim'],
            'pBackOrder' => $row['pbackorder'],
            'pLength' => $row['plength'],
            'pWidth' => $row['pwidth'],
            'pHeight' => $row['pheight'],
            'pWeight' => $row['pweight'],
            'pNumberItems' => $row['pnumberitems'],

            // CS v1.4.2+
            'pMaxQty' => $row['pmaxqty'],
            'pQtyLabel' => $row['pqtylabel'],
            'pAllowDecimalQty' => (isset($row['pallowdecimalqty']) ? $row['pallowdecimalqty'] : false),
            'pQtySteps' => $row['pqtysteps'],
            'pSeperateShip' => $row['pseperateship'],
            'pPackageData' => $row['ppackagedata'],

            // CS v2+
            'pQtyLabel' => (isset($row['pqtylabel']) ? $row['pqtylabel'] : ''),
            'pMaxQty' => (isset($row['pmaxqty']) ? $row['pmaxqty'] : 0),

            // Not supported in CSV data
            'pfID' => null,
            'pVariations' => false,
            'pQuantityPrice' => false,
            'pTaxClass' => 1,        // 1 = default tax class
            // Explicitly set to empty string to prevent null being cast to 0
            'pCostPrice' => '',
            'pWholesalePrice' => ''
        );

        // Process image if imageFile column exists (before saving product)
        if (isset($row['imagefile']) && !empty($row['imagefile'])) {
            $imageFileId = $this->processProductImage(null, $row['imagefile']);
            if ($imageFileId) {
                $data['pfID'] = $imageFileId;
            }
        }

        // Save product
        $p = Product::saveProduct($data);

        // Add product attributes (legacy attr_ format)
        $this->setAttributes($p, $row);

        return $p;
    }

    private function update($p, $row)
    {
        if ($row['psku']) $p->setSKU($row['psku']);
        if ($row['pname']) $p->setName($row['pname']);
        if ($row['pdesc']) $p->setDescription($row['pdesc']);
        if ($row['pdetail']) $p->setDetail($row['pdetail']);
        if ($row['pfeatured']) $p->setIsFeatured($row['pfeatured']);
        if ($row['pqty']) $p->setQty($row['pqty']);
        if ($row['pnoqty']) $p->setNoQty($row['pnoqty']);
        if ($row['ptaxable']) $p->setISTaxable($row['ptaxable']);
        if ($row['pactive']) $p->setIsActive($row['pactive']);
        if ($row['pshippable']) $p->setIsShippable($row['pshippable']);
        if ($row['pcreateuseraccount']) $p->setCreatesUserAccount($row['pcreateuseraccount']);
        if ($row['pautocheckout']) $p->setAutoCheckout($row['pautocheckout']);
        if ($row['pexclusive']) $p->setIsExclusive($row['pexclusive']);

        if ($row['pprice']) $p->setPrice($row['pprice']);
        if ($row['psaleprice']) $p->setSalePrice($row['psaleprice']);
        if ($row['ppricemaximum']) $p->setPriceMaximum($row['ppricemaximum']);
        if ($row['ppriceminimum']) $p->setPriceMinimum($row['ppriceminimum']);
        if ($row['ppricesuggestions']) $p->setPriceSuggestions($row['ppricesuggestions']);
        if ($row['pqtyunlim']) $p->setIsUnlimited($row['pqtyunlim']);
        if ($row['pbackorder']) $p->setAllowBackOrder($row['pbackorder']);
        if ($row['plength']) $p->setLength($row['plength']);
        if ($row['pwidth']) $p->setWidth($row['pwidth']);
        if ($row['pheight']) $p->setHeight($row['pheight']);
        if ($row['pweight']) $p->setWeight($row['pweight']);
        if ($row['pnumberitems']) $p->setNumberItems($row['pnumberitems']);
        
        // CS v1.4.2+
        if ($row['pmaxqty']) $p->setMaxQty($row['pmaxqty']);
        if ($row['pqtylabel']) $p->setQtyLabel($row['pqtylabel']);
        if ($row['pallowdecimalqty']) $p->setAllowDecimalQty($row['pallowdecimalqty']);
        if ($row['pqtysteps']) $p->setQtySteps($row['pqtysteps']);
        if ($row['pseparateship']) $p->setSeparateShip($row['pseparateship']);
        if ($row['ppackagedata']) $p->setPackageData($row['ppackagedata']);

        $config = $this->app->make(Config::class);
        
        // Process image if imageFile column exists
        if (isset($row['imagefile']) && !empty($row['imagefile'])) {
            $imageFileId = $this->processProductImage($p, $row['imagefile']);
            if ($imageFileId) {
                $p->setImageId($imageFileId);
            }
        }

        // Explicitly set cost and wholesale prices to empty string if not in CSV
        // This prevents them from being set to 0 when missing
        if (!isset($row['pcostprice']) || $row['pcostprice'] === '') {
            $p->setCostPrice('');
        } else {
            $p->setCostPrice($row['pcostprice']);
        }
        
        if (!isset($row['pwholesaleprice']) || $row['pwholesaleprice'] === '') {
            $p->setWholesalePrice('');
        } else {
            $p->setWholesalePrice($row['pwholesaleprice']);
        }

        // Product attributes (legacy attr_ format)
        $this->setAttributes($p, $row);

        $p = $p->save();

        return $p;
    }
   
    private function saveSettings()
    {
        $data = $this->post();
        $config = $this->app->make(Config::class);

        // @TODO: Validate post data

        $config->save('community_store_import.import_file', $data['import_file']);
        $config->save('community_store_import.max_execution_time', $data['max_execution_time']);
        $config->save('community_store_import.csv.delimiter', $data['delimiter']);
        $config->save('community_store_import.csv.enclosure', $data['enclosure']);
        $config->save('community_store_import.csv.line_length', $data['line_length']);
    }

    /**
     * Process product image by filename
     * Looks up existing file by filename in the file manager
     * @param Product|null $product Product object (null if called before product creation)
     * @param string $imageFilename Filename from CSV imageFile column
     * @return int|false File ID on success, false on failure
     */
    private function processProductImage($product, $imageFilename)
    {
        // Clean filename - remove any path components for security
        $filename = basename($imageFilename);
        
        // Check if file with same filename already exists
        $existingFile = $this->findExistingFile($filename);
        if ($existingFile) {
            return $existingFile->getFileID();
        }
        
        // If file doesn't exist, return false (image should be uploaded via drag-drop first)
        Log::addInfo('Image file not found in system: ' . $filename . ' - Ensure the file is uploaded via the drag-drop area first.');
        return false;
    }

    /**
     * Process multilingual translations for a product
     * @param Product $product
     * @param array $row CSV row data
     * @param array $multilingualColumns Array of locale => field mappings (e.g., ['ru' => ['pname' => 'pname - ru', 'pdesc' => 'pdesc - ru']])
     * @return bool True if any translations were processed, false otherwise
     */
    private function processMultilingualTranslations($product, $row, $multilingualColumns)
    {
        $em = $this->app->make('Doctrine\ORM\EntityManager');
        $translationsProcessed = false;

        foreach ($multilingualColumns as $locale => $fields) {
            // Normalize locale (e.g., 'ru' -> 'ru_RU')
            $normalizedLocale = $this->normalizeLocale($locale);
            
            // Get product name translation if column exists
            if (isset($fields['pname']) && isset($row[$fields['pname']]) && !empty(trim($row[$fields['pname']]))) {
                $translatedName = trim($row[$fields['pname']]);
                $this->saveTranslation($em, $product->getID(), 'productName', $normalizedLocale, $translatedName, false);
                $translationsProcessed = true;
            }

            // Get product description translation if column exists
            if (isset($fields['pdesc']) && isset($row[$fields['pdesc']]) && !empty(trim($row[$fields['pdesc']]))) {
                $translatedDesc = trim($row[$fields['pdesc']]);
                $this->saveTranslation($em, $product->getID(), 'productDescription', $normalizedLocale, $translatedDesc, true);
                $translationsProcessed = true;
            }

            // Get product detail translation if column exists
            if (isset($fields['pdetail']) && isset($row[$fields['pdetail']]) && !empty(trim($row[$fields['pdetail']]))) {
                $translatedDetail = trim($row[$fields['pdetail']]);
                $this->saveTranslation($em, $product->getID(), 'productDetails', $normalizedLocale, $translatedDetail, true);
                $translationsProcessed = true;
            }

            // Update multilingual product pages if they exist
            if ($translationsProcessed) {
                $this->updateMultilingualProductPage($product, $normalizedLocale);
            }
        }

        return $translationsProcessed;
    }

    /**
     * Save a translation to the database
     * @param \Doctrine\ORM\EntityManager $em Entity manager
     * @param int $productID Product ID
     * @param string $entityType Translation entity type (e.g., 'productName', 'productDescription')
     * @param string $locale Locale code (e.g., 'ru_RU')
     * @param string $text Translated text
     * @param bool $isLongText Whether this is a long text (uses extendedText) or short text (uses translatedText)
     */
    private function saveTranslation($em, $productID, $entityType, $locale, $text, $isLongText = false)
    {
        // Check if translation already exists
        $qb = $em->createQueryBuilder();
        $query = $qb->select('t')
            ->from('Concrete\Package\CommunityStore\Src\CommunityStore\Multilingual\Translation', 't')
            ->where('t.entityType = :type')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.pID = :pid')
            ->setParameter('type', $entityType)
            ->setParameter('locale', $locale)
            ->setParameter('pid', $productID)
            ->setMaxResults(1)
            ->getQuery();

        $existing = $query->getResult();

        if (!empty($existing)) {
            $translation = $existing[0];
        } else {
            $translation = new Translation();
            $translation->setProductID($productID);
            $translation->setEntityType($entityType);
            $translation->setLocale($locale);
        }

        if ($isLongText) {
            $translation->setExtendedText($text);
        } else {
            $translation->setTranslatedText($text);
        }

        $translation->save();

        Log::addInfo("Saved translation for product ID {$productID}, type: {$entityType}, locale: {$locale}");
    }

    /**
     * Normalize locale code (e.g., 'ru' -> 'ru_RU', 'en' -> 'en_US')
     * This is a simple mapping - you may need to adjust based on your locale setup
     * @param string $locale Short locale code
     * @return string Normalized locale code
     */
    private function normalizeLocale($locale)
    {
        // Common locale mappings
        $localeMap = [
            'ru' => 'ru_RU',
            'en' => 'en_US',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'pt' => 'pt_PT',
            'zh' => 'zh_CN',
            'ja' => 'ja_JP',
        ];

        $locale = strtolower($locale);
        
        // If already in format like 'ru_RU', return as-is
        if (strpos($locale, '_') !== false) {
            return $locale;
        }

        // Map common 2-letter codes
        if (isset($localeMap[$locale])) {
            return $localeMap[$locale];
        }

        // Default: return as-is if no mapping found
        return $locale;
    }

    /**
     * Update multilingual product page with translated name
     * @param Product $product
     * @param string $locale Locale code
     */
    private function updateMultilingualProductPage($product, $locale)
    {
        try {
            $productPage = $product->getProductPage();
            if (!$productPage || $productPage->isError()) {
                return; // No product page to update
            }

            $csm = $this->app->make('cs/helper/multilingual');
            $mlist = Section::getList();

            // Find the section for this locale
            foreach ($mlist as $section) {
                if ($section->getLocale() === $locale) {
                    $relatedID = $section->getTranslatedPageID($productPage);
                    
                    if ($relatedID) {
                        $translatedPage = Page::getByID($relatedID);
                        
                        if ($translatedPage && !$translatedPage->isError()) {
                            // Update page name with translated product name
                            $productName = $csm->t(null, 'productName', $product->getID(), false, $locale);
                            if ($productName) {
                                $translatedPage->update(['cName' => $productName]);
                            }
                        }
                    } else {
                        // Product page exists but multilingual page doesn't - create it
                        $parentPage = $productPage->getParent();
                        if ($parentPage && !$parentPage->isError()) {
                            $relatedParentID = $section->getTranslatedPageID($parentPage);
                            if ($relatedParentID) {
                                $translatedParentPage = Page::getByID($relatedParentID);
                                if ($translatedParentPage && !$translatedParentPage->isError()) {
                                    // Duplicate the product page to create multilingual version
                                    $translatedPage = $productPage->duplicate($translatedParentPage);
                                    if ($translatedPage && !$translatedPage->isError()) {
                                        $productName = $csm->t(null, 'productName', $product->getID(), false, $locale);
                                        if ($productName) {
                                            $translatedPage->update(['cName' => $productName]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                    break;
                }
            }
        } catch (Exception $e) {
            Log::addWarning("Failed to update multilingual product page for product ID {$product->getID()}, locale: {$locale} - " . $e->getMessage());
        }
    }

    /**
     * Generate product page if it doesn't exist
     * @param Product $product
     * @return bool True if page was created, false otherwise
     */
    private function generateProductPage($product)
    {
        // Check if product already has a page
        if ($product->getPageID()) {
            return false;
        }

        try {
            // Use the Product's generatePage method to create the page
            if ($product->generatePage()) {
                Log::addInfo('Product page created for product: ' . $product->getName() . ' (SKU: ' . $product->getSKU() . ')');
                return true;
            } else {
                Log::addWarning('Failed to create product page for: ' . $product->getName() . ' (SKU: ' . $product->getSKU() . ') - Product publish target may not be configured');
                return false;
            }
        } catch (Exception $e) {
            Log::addWarning('Error creating product page for: ' . $product->getName() . ' (SKU: ' . $product->getSKU() . ') - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find existing file by filename
     * @param string $filename
     * @return File|false File object if found, false otherwise
     */
    private function findExistingFile($filename)
    {
        try {
            $db = \Database::connection();
            
            // Query for files with matching approved version filename (case-insensitive)
            // Use LOWER() for case-insensitive comparison as filename matching should be case-insensitive
            $query = "SELECT f.fID FROM Files f 
                      INNER JOIN FileVersions fv ON f.fID = fv.fID 
                      WHERE LOWER(fv.fvFilename) = LOWER(?) 
                      AND fv.fvIsApproved = 1 
                      ORDER BY fv.fvID DESC 
                      LIMIT 1";
            
            $fileID = $db->fetchColumn($query, [$filename]);
            
            if ($fileID) {
                $file = File::getByID($fileID);
                if ($file && !$file->isError()) {
                    return $file;
                }
            }
        } catch (Exception $e) {
            // If there's an error, just continue and upload new file
            Log::addWarning('Error checking for existing file: ' . $filename . ' - ' . $e->getMessage());
        }
        
        return false;
    }

    private function isValid($headings)
    {
        // @TODO: implement

        // @TODO: interrogate database for non-null fields
        $config = $this->app->make(Config::class);
        $dbname = $config->get('database.connections.concrete.database');

        /*
            SELECT GROUP_CONCAT(column_name) nonnull_columns
            FROM information_schema.columns
            WHERE table_schema = '$dbname'
                AND table_name = 'CommunityStoreProducts'
                AND is_nullable = 'NO'
                // pfID is excluded because it is not-null but also an optional field
                AND column_name not in ('pID', 'pfID', pDateAdded');
        */

        return (false);
    }
}

