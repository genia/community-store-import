<?php
namespace Concrete\Package\CommunityStoreImport\Controller\SinglePage\Dashboard\Store\Products;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\File\File;
use Concrete\Core\File\Importer;
use Concrete\Core\File\Service\File as FileService;
use Concrete\Core\Support\Facade\Log;
use Concrete\Core\Config\Repository\Repository as Config;
use Exception;

use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Entity\Attribute\Key\StoreProductKey;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group as StoreGroup;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductGroup;


class Import extends DashboardPageController
{
    public $helpers = array('form', 'concrete/asset_library', 'json');
    private $attributes = array();

    public function view()
    {
        $this->loadFormAssets();
        $this->set('pageTitle', t('Product Import'));
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

        $f = File::getByID($config->get('community_store_import.import_file'));
        $fname = $_SERVER['DOCUMENT_ROOT'] . $f->getApprovedVersion()->getRelativePath();

        if (!file_exists($fname) || !is_readable($fname)) {
            $this->error->add(t("Import file not found or is not readable."));
            return;
        }

        if (!$handle = @fopen($fname, 'r')) {
            $this->error->add(t('Cannot open file %s.', $fname));
            return;
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

        // Get attribute headings
        foreach ($headings as $heading) {
            if (preg_match('/^attr_/', $heading)) {
                $this->attributes[] = $heading;
            }
        }

        $updated = 0;
        $added = 0;
        $imagesProcessed = 0;
        $imagesFailed = 0;
        $pagesCreated = 0;

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

            // @TODO: dispatch events - see Products::save()
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
        
        $this->set('success', $this->get('success') . $successMsg);
        Log::addInfo($this->get('success'));

        ini_set('auto_detect_line_endings', FALSE);
        ini_set('max_execution_time', $MAX_EXECUTION_TIME);
        ini_set('max_input_time', $MAX_INPUT_TIME);
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

    private function setGroups($product, $row) {
        if ($row['pproductgroups']) {
            $pGroupNames = explode(',', $row['pproductgroups']);
            $pGroups = array();
            foreach ($pGroupNames as $pGroupName) {
                $storeGroup = StoreGroup::getByName($pGroupName);
                if (! $storeGroup instanceof StoreGroup) {
                    $storeGroup = StoreGroup::add($pGroupName);
                }
                $pGroups[] = $storeGroup;
            }
            $data['pProductGroups'] = $pGroups;

            // Update groups
            ProductGroup::addGroupsForProduct($data, $product);
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
            'pfID' => $this->app->make(Config::class)->get('community_store_import.default_image'),
            'pVariations' => false,
            'pQuantityPrice' => false,
            'pTaxClass' => 1        // 1 = default tax class
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

        // Add product attributes
        $this->setAttributes($p, $row);
        
        // Add product groups
        $this->setGroups($p, $row);

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
        } elseif (! $p->getImageId()) {
            // Only use default if no image was set
            $p->setImageId($config->get('community_store_import.default_image'));
        }

        // Product attributes
        $this->setAttributes($p, $row);

        // Product groups
        $this->setGroups($p, $row);

        $p = $p->save();

        return $p;
    }
   
    private function saveSettings()
    {
        $data = $this->post();
        $config = $this->app->make(Config::class);

        // @TODO: Validate post data

        $config->save('community_store_import.import_file', $data['import_file']);
        $config->save('community_store_import.default_image', $data['default_image']);
        $config->save('community_store_import.image_directory', isset($data['image_directory']) ? $data['image_directory'] : '');
        $config->save('community_store_import.max_execution_time', $data['max_execution_time']);
        $config->save('community_store_import.csv.delimiter', $data['delimiter']);
        $config->save('community_store_import.csv.enclosure', $data['enclosure']);
        $config->save('community_store_import.csv.line_length', $data['line_length']);
    }

    /**
     * Process and upload product image from filesystem
     * @param Product|null $product Product object (null if called before product creation)
     * @param string $imageFilename Filename or full path from CSV imageFile column
     * @return int|false File ID on success, false on failure
     */
    private function processProductImage($product, $imageFilename)
    {
        // Check if imageFile contains a full path (has directory separators)
        $hasPathSeparator = (strpos($imageFilename, '/') !== false || strpos($imageFilename, '\\') !== false);
        
        if ($hasPathSeparator) {
            // Use the full path directly
            $imagePath = $imageFilename;
        } else {
            // Use configured image directory + filename
            $config = $this->app->make(Config::class);
            $imageDir = $config->get('community_store_import.image_directory');
            
            if (empty($imageDir) || !is_dir($imageDir)) {
                return false;
            }

            // Clean filename - remove any path components for security
            $imageFilename = basename($imageFilename);
            $imagePath = rtrim($imageDir, '/') . '/' . $imageFilename;
        }

        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            return false;
        }

        // Check if file is a valid image
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        try {
            // Get just the filename for import (use basename of the path)
            $importFilename = basename($imagePath);
            
            // Check if file with same filename already exists
            $existingFile = $this->findExistingFile($importFilename);
            if ($existingFile) {
                return $existingFile->getFileID();
            }
            
            // Import file into ConcreteCMS file manager
            $importer = $this->app->make(Importer::class);
            $fileService = $this->app->make(FileService::class);
            
            // Copy file to a temporary location with a unique name to avoid conflicts
            $tempName = uniqid('import_', true) . '.' . $extension;
            $tempPath = $fileService->getTemporaryDirectory() . '/' . $tempName;
            
            if (!copy($imagePath, $tempPath)) {
                return false;
            }

            // Import the file
            $fv = $importer->import($tempPath, $importFilename, null);
            
            // Clean up temp file
            @unlink($tempPath);

            if ($fv instanceof \Concrete\Core\Entity\File\Version) {
                $file = $fv->getFile();
                return $file->getFileID();
            }
        } catch (Exception $e) {
            // Log error but don't stop import
            Log::addWarning('Failed to import image: ' . $imageFilename . ' - ' . $e->getMessage());
            return false;
        }

        return false;
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
            
            // Query for files with matching approved version filename
            $query = "SELECT f.fID FROM Files f 
                      INNER JOIN FileVersions fv ON f.fID = fv.fID 
                      WHERE fv.fvFilename = ? 
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

