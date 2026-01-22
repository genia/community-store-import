<?php
namespace Concrete\Package\CommunityStoreImport\Controller\SinglePage\Dashboard\Store\Products;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\File\File;
use Concrete\Core\File\Importer;
use Concrete\Core\File\Service\File as FileService;
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
        // Ensure error logging is enabled (fallback if .htaccess doesn't work)
        ini_set('log_errors', '1');
        ini_set('error_log', '/tmp/php_errors.log');
        ini_set('error_reporting', E_ALL);
        
        $this->saveSettings();

        $config = $this->app->make(Config::class);
        $MAX_TIME = $config->get('community_store_import.max_execution_time');
        $MAX_EXECUTION_TIME = ini_get('max_execution_time');
        $MAX_INPUT_TIME = ini_get('max_input_time');
        ini_set('max_execution_time', $MAX_TIME);
        ini_set('max_input_time', $MAX_TIME);
        @ini_set('auto_detect_line_endings', TRUE); // Suppress deprecation warning in PHP 8.1+

        $data = $this->post();
        $isGoogleSheets = true; // Always using Google Sheets now
        $googleSheetsImageMap = []; // Map row index => image file path for embedded images
        $googleSheetsData = null; // Parsed data from Google Sheets HTML
        $headings = [];
        $allRows = []; // All data rows to process

        // Google Sheets URL is required
        if (empty($data['google_sheets_url'])) {
            $this->error->add(t("Google Sheets URL is required."));
            return;
        }
        
        // Extract spreadsheet ID
        if (!preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $data['google_sheets_url'], $matches)) {
            $this->error->add(t("Invalid Google Sheets URL."));
            return;
        }
        $spreadsheetId = $matches[1];
        
        // Download and parse the zip export (HTML + images)
        Log::addInfo('Parsing Google Sheets zip export for spreadsheet ID: ' . $spreadsheetId);
        $googleSheetsData = $this->parseGoogleSheetsZip($spreadsheetId);
        
        if ($googleSheetsData === false) {
            // Check the log for specific failure reason
            $this->error->add(t("Failed to fetch data from Google Sheets. Check the logs for details. Spreadsheet ID: %s", $spreadsheetId));
            return;
        }
        
        if (empty($googleSheetsData['headings'])) {
            $this->error->add(t("No data found in Google Sheets. The sheet may be empty or the format is not recognized."));
            return;
        }
        
        $headings = $googleSheetsData['headings'];
        $allRows = $googleSheetsData['rows'];
        $googleSheetsImageMap = $googleSheetsData['images'];
        $isGoogleSheets = true;
        
        Log::addInfo('Google Sheets parsed: ' . count($headings) . ' columns, ' . count($allRows) . ' rows, ' . count($googleSheetsImageMap) . ' images');

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
        $embeddedImagesFound = count($googleSheetsImageMap);
        $embeddedImagesProcessed = 0;

        // Process all rows
        foreach ($allRows as $rowIndex => $csvRow) {
            // Ensure row has same number of columns as headings
            if (count($csvRow) !== count($headings)) {
                // Pad or trim to match
                while (count($csvRow) < count($headings)) {
                    $csvRow[] = '';
                }
                $csvRow = array_slice($csvRow, 0, count($headings));
            }
            
            // Make associative array
            $row = array_combine($headings, $csvRow);
            
            // Skip rows without required data (must have at least SKU or name)
            $hasSku = isset($row['psku']) && trim($row['psku']) !== '';
            $hasName = isset($row['pname']) && trim($row['pname']) !== '';
            
            if (!$hasSku && !$hasName) {
                Log::addInfo("Skipping row $rowIndex: no SKU or name");
                continue;
            }

            $p = Product::getBySKU($row['psku']);
            
            $imageProcessed = false;
            $oldImageId = null;
            if ($p instanceof Product) {
                $oldImageId = $p->getImageId();
                $this->update($p, $row, $isGoogleSheets, $googleSheetsImageMap, $rowIndex);
                $updated++;
                $newImageId = $p->getImageId();
                // Check if image was updated (either from filename or embedded)
                if (isset($row['imagefile'])) {
                    $imageProcessed = ($newImageId && $newImageId != $oldImageId);
                }
            } else {
                $p = $this->add($row, $isGoogleSheets, $googleSheetsImageMap, $rowIndex);
                $added++;
                // Check if image was set for new product
                if (isset($row['imagefile'])) {
                    $imageProcessed = (bool)$p->getImageId();
                }
            }

            // Count images
            if (isset($row['imagefile'])) {
                if ($imageProcessed) {
                    $imagesProcessed++;
                } elseif (isset($row['imagefile']) && (trim($row['imagefile']) !== '' || ($isGoogleSheets && isset($googleSheetsImageMap[$rowIndex])))) {
                    // Only count as failed if there was an image attempt
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
        if ($embeddedImagesFound > 0) {
            $successMsg .= " Embedded images found: $embeddedImagesFound";
            if ($embeddedImagesProcessed > 0) {
                $successMsg .= ", processed: $embeddedImagesProcessed";
            }
        }
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
        
        // Clean up Google Sheets extract directory
        if ($this->googleSheetsExtractDir && is_dir($this->googleSheetsExtractDir)) {
            $this->recursiveDelete($this->googleSheetsExtractDir);
            Log::addInfo('Cleaned up temp directory: ' . $this->googleSheetsExtractDir);
        }
        
        $this->set('success', $this->get('success') . $successMsg);
        Log::addInfo($this->get('success'));

        @ini_set('auto_detect_line_endings', FALSE); // Suppress deprecation warning in PHP 8.1+
        ini_set('max_execution_time', $MAX_EXECUTION_TIME);
        ini_set('max_input_time', $MAX_INPUT_TIME);
    }

    /**
     * Parse Google Sheets zip export to get data rows and embedded images
     * @param string $spreadsheetId Google Sheets spreadsheet ID
     * @return array|false Returns ['headings' => [...], 'rows' => [...], 'images' => [...]] or false on failure
     */
    private function parseGoogleSheetsZip($spreadsheetId)
    {
        try {
            // Download the zip export which includes HTML + images folder
            // URL format: https://docs.google.com/spreadsheets/d/{ID}/export?format=zip
            $zipUrl = 'https://docs.google.com/spreadsheets/d/' . $spreadsheetId . '/export?format=zip';
            
            Log::addInfo('Downloading Google Sheets zip from: ' . $zipUrl);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept: */*'
                    ],
                    'timeout' => 60,
                    'follow_location' => 1,
                    'max_redirects' => 10
                ]
            ]);
            
            $zipContent = @file_get_contents($zipUrl, false, $context);
            
            if ($zipContent === false) {
                Log::addWarning('Failed to download Google Sheets zip export from: ' . $zipUrl);
                return false;
            }
            
            // Check if we got HTML error page instead of zip
            if (strlen($zipContent) < 1000 && (stripos($zipContent, '<html') !== false || stripos($zipContent, '<!doctype') !== false)) {
                Log::addWarning('Google Sheets returned HTML error page instead of zip. Content: ' . substr($zipContent, 0, 500));
                return false;
            }
            
            Log::addInfo('Downloaded zip export, size: ' . strlen($zipContent) . ' bytes');
            
            // Save zip to temp file
            $fileService = $this->app->make(FileService::class);
            $tempDir = $fileService->getTemporaryDirectory();
            $zipPath = $tempDir . '/google_sheets_' . uniqid() . '.zip';
            file_put_contents($zipPath, $zipContent);
            
            // Extract zip
            $extractDir = $tempDir . '/google_sheets_extract_' . uniqid();
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                Log::addWarning('Failed to open zip file: ' . $zipPath);
                @unlink($zipPath);
                return false;
            }
            
            $zip->extractTo($extractDir);
            $zip->close();
            @unlink($zipPath);
            Log::addInfo('Extracted zip to: ' . $extractDir);
            
            // Store extract directory for cleanup later
            $this->googleSheetsExtractDir = $extractDir;
            
            // Find the HTML file
            $htmlFiles = glob($extractDir . '/*.html');
            if (empty($htmlFiles)) {
                $htmlFiles = glob($extractDir . '/*/*.html');
            }
            
            if (empty($htmlFiles)) {
                Log::addWarning('No HTML file found in zip export');
                return false;
            }
            
            $htmlFile = $htmlFiles[0];
            $htmlContent = file_get_contents($htmlFile);
            $htmlDir = dirname($htmlFile);
            
            Log::addInfo('Found HTML file: ' . $htmlFile . ' (' . strlen($htmlContent) . ' bytes)');
            
            // Check for resources folder with images
            $resourcesDir = $htmlDir . '/resources';
            $resourceImages = [];
            if (is_dir($resourcesDir)) {
                $imageFiles = glob($resourcesDir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
                Log::addInfo('Found ' . count($imageFiles) . ' images in resources folder');
                foreach ($imageFiles as $imgFile) {
                    Log::addInfo('  - ' . basename($imgFile));
                    $resourceImages[] = $imgFile;
                }
            } else {
                Log::addInfo('No resources folder found at: ' . $resourcesDir);
                // Try looking for images directly in extract dir
                $imageFiles = glob($extractDir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
                if (!empty($imageFiles)) {
                    Log::addInfo('Found ' . count($imageFiles) . ' images in extract dir');
                    $resourceImages = $imageFiles;
                }
            }
            
            // Parse HTML table
            $result = [
                'headings' => [],
                'rows' => [],
                'images' => []
            ];
            
            // Use DOMDocument for reliable HTML parsing
            $dom = new \DOMDocument();
            @$dom->loadHTML($htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new \DOMXPath($dom);
            
            // Find all rows in the table
            $tableRows = $xpath->query('//table//tr');
            Log::addInfo('Found ' . $tableRows->length . ' table rows');
            $isHeader = true;
            $dataRowIndex = 0;
            
            foreach ($tableRows as $tr) {
                $cells = $xpath->query('.//td | .//th', $tr);
                $rowData = [];
                
                foreach ($cells as $cell) {
                    // Get text content, stripping tags
                    $cellText = trim($cell->textContent);
                    $rowData[] = $cellText;
                    
                    // Check for embedded image in this cell (only for data rows)
                    if (!$isHeader) {
                        $imgs = $xpath->query('.//img', $cell);
                        foreach ($imgs as $img) {
                            $src = $img->getAttribute('src');
                            if (!empty($src)) {
                                Log::addInfo("Row $dataRowIndex: Found img tag with src: " . $src);
                                
                                // Try multiple patterns for image paths
                                $imagePath = null;
                                
                                // Pattern 1: cellImage_X_Y.ext in resources folder
                                if (preg_match('/cellImage_\d+_\d+\.(jpg|jpeg|png|gif|webp)/i', $src)) {
                                    $imagePath = $htmlDir . '/' . $src;
                                }
                                // Pattern 2: Direct relative path
                                elseif (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $src)) {
                                    $imagePath = $htmlDir . '/' . $src;
                                }
                                // Pattern 3: resources/image.ext
                                elseif (preg_match('/^resources\//i', $src)) {
                                    $imagePath = $htmlDir . '/' . $src;
                                }
                                
                                if ($imagePath) {
                                    Log::addInfo("Looking for image at: " . $imagePath);
                                    if (file_exists($imagePath)) {
                                        $result['images'][$dataRowIndex] = $imagePath;
                                        Log::addInfo("Row $dataRowIndex has image: " . basename($src));
                                    } else {
                                        Log::addWarning("Image file not found: " . $imagePath);
                                    }
                                }
                            }
                        }
                    }
                }
                
                if ($isHeader) {
                    // Look for the actual header row - it should contain column names like pSKU, pName, etc.
                    // Skip rows that look like column letters (A, B, C) or row numbers
                    $nonEmptyValues = array_filter($rowData, function($v) { return trim($v) !== ''; });
                    
                    if (!empty($nonEmptyValues)) {
                        // Check if this looks like a header row (contains psku, pname, etc.)
                        $lowerValues = array_map('strtolower', $rowData);
                        $hasProductColumns = in_array('psku', $lowerValues) || in_array('pname', $lowerValues);
                        
                        // Check if this is just column letters (A, B, C, D...)
                        $isColumnLetters = true;
                        foreach ($nonEmptyValues as $val) {
                            $val = trim($val);
                            // Column letters are single uppercase letters, or row numbers
                            if (!preg_match('/^[A-Z]{1,2}$/', $val) && !preg_match('/^\d+$/', $val)) {
                                $isColumnLetters = false;
                                break;
                            }
                        }
                        
                        if ($hasProductColumns && !$isColumnLetters) {
                            $result['headings'] = array_map('strtolower', $rowData);
                            $isHeader = false;
                            Log::addInfo('Found header row with columns: ' . implode(', ', array_slice($result['headings'], 0, 10)));
                        } else {
                            Log::addInfo('Skipping non-header row: ' . implode(', ', array_slice($rowData, 0, 5)));
                        }
                    }
                } else {
                    // Skip empty rows and rows that only contain row numbers
                    $nonEmptyValues = array_filter($rowData, function($v) { return trim($v) !== ''; });
                    
                    // Check if this row has actual data (not just a row number in first column)
                    $hasRealData = false;
                    foreach ($nonEmptyValues as $idx => $val) {
                        $val = trim($val);
                        // If any cell (except potentially the first) has non-numeric content, it's real data
                        if ($idx > 0 || !preg_match('/^\d+$/', $val)) {
                            $hasRealData = true;
                            break;
                        }
                    }
                    
                    if ($hasRealData && count($nonEmptyValues) > 1) {
                        $result['rows'][] = $rowData;
                        $dataRowIndex++;
                    } else if (!empty($nonEmptyValues)) {
                        Log::addInfo('Skipping row with insufficient data: ' . implode(', ', array_slice($rowData, 0, 5)));
                    }
                }
            }
            
            // Fallback: If no images were matched via HTML parsing but we have images in resources,
            // try to match them by filename pattern (cellImage_ROW_COL.ext)
            if (empty($result['images']) && !empty($resourceImages)) {
                Log::addInfo('Attempting fallback image matching by filename pattern');
                foreach ($resourceImages as $imgPath) {
                    $filename = basename($imgPath);
                    // Pattern: cellImage_ROW_COL.ext (ROW is 0-based data row index)
                    if (preg_match('/cellImage_(\d+)_(\d+)\./i', $filename, $matches)) {
                        $rowIdx = (int)$matches[1];
                        if ($rowIdx < count($result['rows']) && !isset($result['images'][$rowIdx])) {
                            $result['images'][$rowIdx] = $imgPath;
                            Log::addInfo("Matched image by pattern: $filename -> row $rowIdx");
                        }
                    }
                }
            }
            
            Log::addInfo('Parsed ' . count($result['rows']) . ' data rows, ' . count($result['images']) . ' embedded images');
            return $result;
            
        } catch (Exception $e) {
            Log::addWarning('Error parsing Google Sheets: ' . $e->getMessage());
            return false;
        }
    }
    
    /** @var string|null Temporary directory for Google Sheets extract */
    private $googleSheetsExtractDir = null;

    /**
     * Process an embedded image from Google Sheets
     * Handles both local file paths (from zip extract) and URLs
     * @param string $imagePathOrUrl Local file path or URL to the embedded image
     * @return int|false File ID on success, false on failure
     */
    private function processGoogleSheetsEmbeddedImage($imagePathOrUrl)
    {
        if (empty($imagePathOrUrl)) {
            return false;
        }
        
        try {
            $imageData = null;
            $originalFilename = null;
            
            // Check if it's a local file path or URL
            if (file_exists($imagePathOrUrl)) {
                // Local file from zip extract
                $imageData = file_get_contents($imagePathOrUrl);
                $originalFilename = basename($imagePathOrUrl);
                Log::addInfo('Reading local image file: ' . $imagePathOrUrl);
            } else {
                // URL - download the image
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => [
                            'User-Agent: Mozilla/5.0 (compatible; ConcreteCMS Import)',
                            'Accept: image/*'
                        ],
                        'timeout' => 30,
                        'follow_location' => 1,
                        'max_redirects' => 5
                    ]
                ]);
                
                $imageData = @file_get_contents($imagePathOrUrl, false, $context);
                $originalFilename = basename(parse_url($imagePathOrUrl, PHP_URL_PATH));
            }
            
            if ($imageData === false || empty($imageData)) {
                Log::addWarning('Failed to read image: ' . $imagePathOrUrl);
                return false;
            }
            
            // Determine file extension from filename or content
            $extension = 'jpg'; // default
            if ($originalFilename && preg_match('/\.(jpg|jpeg|png|gif|webp)/i', $originalFilename, $matches)) {
                $extension = strtolower($matches[1]);
                if ($extension === 'jpeg') {
                    $extension = 'jpg';
                }
            } elseif (function_exists('getimagesizefromstring')) {
                $imageInfo = @getimagesizefromstring($imageData);
                if ($imageInfo) {
                    $mimeToExt = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp'
                    ];
                    if (isset($mimeToExt[$imageInfo['mime']])) {
                        $extension = $mimeToExt[$imageInfo['mime']];
                    }
                }
            }
            
            // Generate a unique filename
            $filename = 'product_' . uniqid() . '.' . $extension;
            
            // Save to temporary file
            $fileService = $this->app->make(FileService::class);
            $tempPath = $fileService->getTemporaryDirectory() . '/' . $filename;
            file_put_contents($tempPath, $imageData);
            
            Log::addInfo('Saved temp image: ' . $tempPath . ' (' . strlen($imageData) . ' bytes)');
            
            // Import into ConcreteCMS
            $importer = $this->app->make(Importer::class);
            $fv = $importer->import($tempPath, $filename, null);
            
            // Clean up temp file
            @unlink($tempPath);
            
            if ($fv instanceof \Concrete\Core\Entity\File\Version) {
                $file = $fv->getFile();
                return $file->getFileID();
            }
        } catch (Exception $e) {
            Log::addWarning('Failed to process Google Sheets embedded image: ' . $e->getMessage());
        }
        
        return false;
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

    private function add($row, $isGoogleSheets = false, $googleSheetsImageMap = [], $rowIndex = 0)
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
            'pfID' => 0,  // Default to 0 (no image) - prod database requires NOT NULL
            'pVariations' => false,
            'pQuantityPrice' => false,
            'pTaxClass' => 1,        // 1 = default tax class
            // Explicitly set to empty string to prevent null being cast to 0
            'pCostPrice' => '',
            'pWholesalePrice' => ''
        );

            // Process image if imageFile column exists (before saving product)
            if (isset($row['imagefile'])) {
                $imageFileId = null;
                
                // For Google Sheets, check for embedded images first
                if ($isGoogleSheets && isset($googleSheetsImageMap[$rowIndex])) {
                    Log::addInfo('Processing embedded image for row ' . $rowIndex . ': ' . (is_string($googleSheetsImageMap[$rowIndex]) ? substr($googleSheetsImageMap[$rowIndex], 0, 100) : 'data'));
                    // Use embedded image from Google Sheets
                    $imageFileId = $this->processGoogleSheetsEmbeddedImage($googleSheetsImageMap[$rowIndex]);
                    if ($imageFileId) {
                        $data['pfID'] = $imageFileId;
                        $embeddedImagesProcessed++;
                        Log::addInfo('Successfully uploaded embedded image, file ID: ' . $imageFileId);
                    } else {
                        Log::addWarning('Failed to upload embedded image for row ' . $rowIndex);
                    }
                }
                
                // If no embedded image was used, try filename from CSV
                if (!$imageFileId && !empty(trim($row['imagefile']))) {
                    $imageFileId = $this->processProductImage(null, $row['imagefile']);
                    if ($imageFileId) {
                        $data['pfID'] = $imageFileId;
                    }
                }
            }

        // Save product
        $p = Product::saveProduct($data);

        // Add product attributes (legacy attr_ format)
        $this->setAttributes($p, $row);

        return $p;
    }

    private function update($p, $row, $isGoogleSheets = false, $googleSheetsImageMap = [], $rowIndex = 0)
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
        if (isset($row['imagefile'])) {
            $imageFileId = null;
            
            if ($isGoogleSheets) {
                // Check for embedded Google Sheets image first
                if (isset($googleSheetsImageMap[$rowIndex])) {
                    Log::addInfo('Processing embedded image for row ' . $rowIndex . ' in update(): ' . (is_string($googleSheetsImageMap[$rowIndex]) ? substr($googleSheetsImageMap[$rowIndex], 0, 100) : 'data'));
                    // Use embedded image from Google Sheets
                    $imageFileId = $this->processGoogleSheetsEmbeddedImage($googleSheetsImageMap[$rowIndex]);
                    if ($imageFileId) {
                        $p->setImageId($imageFileId);
                        $embeddedImagesProcessed++;
                        Log::addInfo('Successfully updated product with embedded image, file ID: ' . $imageFileId);
                    } else {
                        Log::addWarning('Failed to upload embedded image for row ' . $rowIndex . ' in update()');
                    }
                }
                
                // If no embedded image was used, try filename from CSV
                if (!$imageFileId && !empty(trim($row['imagefile']))) {
                    $imageFileId = $this->processProductImage($p, $row['imagefile']);
                    if ($imageFileId) {
                        $p->setImageId($imageFileId);
                    }
                }
            } else {
                // Regular CSV file - use filename
                if (!empty(trim($row['imagefile']))) {
                    $imageFileId = $this->processProductImage($p, $row['imagefile']);
                    if ($imageFileId) {
                        $p->setImageId($imageFileId);
                    }
                }
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

        $config->save('community_store_import.max_execution_time', $data['max_execution_time']);
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
        $imageFilename = trim($imageFilename);
        
        // Check if this is a direct image URL
        if (preg_match('/^https?:\/\//i', $imageFilename)) {
            Log::addInfo('Detected image URL: ' . $imageFilename);
            return $this->processImageUrl($imageFilename);
        }
        
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
     * Download and import an image from a URL
     * @param string $imageUrl The direct image URL
     * @return int|false File ID on success, false on failure
     */
    private function processImageUrl($imageUrl)
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept: image/webp,image/apng,image/*,*/*;q=0.8'
                    ],
                    'timeout' => 30,
                    'follow_location' => 1,
                    'max_redirects' => 10
                ]
            ]);
            
            $imageData = @file_get_contents($imageUrl, false, $context);
            
            if ($imageData === false || empty($imageData)) {
                Log::addWarning('Failed to download image from URL: ' . $imageUrl);
                return false;
            }
            
            Log::addInfo('Downloaded image, size: ' . strlen($imageData) . ' bytes');
            
            // Determine file extension from content
            $extension = 'jpg'; // default
            if (function_exists('getimagesizefromstring')) {
                $imageInfo = @getimagesizefromstring($imageData);
                if ($imageInfo) {
                    $mimeToExt = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp'
                    ];
                    if (isset($mimeToExt[$imageInfo['mime']])) {
                        $extension = $mimeToExt[$imageInfo['mime']];
                    }
                }
            }
            
            // Generate a unique filename
            $filename = 'product_' . uniqid() . '.' . $extension;
            
            // Save to temporary file
            $fileService = $this->app->make(FileService::class);
            $tempPath = $fileService->getTemporaryDirectory() . '/' . $filename;
            file_put_contents($tempPath, $imageData);
            
            // Import the file
            $importer = new Importer();
            $result = $importer->import($tempPath, $filename);
            
            // Clean up temp file
            @unlink($tempPath);
            
            if ($result instanceof \Concrete\Core\File\Version\Version) {
                $fileId = $result->getFile()->getFileID();
                Log::addInfo('Successfully imported image from URL, file ID: ' . $fileId);
                return $fileId;
            } elseif (is_object($result) && method_exists($result, 'getFileID')) {
                $fileId = $result->getFileID();
                Log::addInfo('Successfully imported image from URL, file ID: ' . $fileId);
                return $fileId;
            }
            
            Log::addWarning('Failed to import image from URL: ' . $imageUrl);
            return false;
            
        } catch (\Exception $e) {
            Log::addWarning('Error downloading image from URL: ' . $e->getMessage());
            return false;
        }
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

    /**
     * Recursively delete a directory and its contents
     * @param string $dir Directory path to delete
     */
    private function recursiveDelete($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

