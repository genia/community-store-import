<?php  defined('C5_EXECUTE') or die('Access denied'); ?>

<?php
use Concrete\Core\File\File;
use Concrete\Core\Config\Repository\Repository as Config;
use Concrete\Core\Support\Facade\Application;

$app = Application::getFacadeApplication();
$config = $app->make(Config::class);
$importFID = File::getByID(intval($config->get('community_store_import.import_file')));
?>

<form method="post" class="form-horizontal" id="import-form" action="<?php echo $view->action('run') ?>" >
    <?php echo $this->controller->token->output('run_import')?>

    <fieldset>
        <legend><?php echo t('Settings') ?></legend>

        <div class="col-md-6">
            <div class="alert alert-warning">
                <?php echo t('Before running this import, it is strongly recommended that you backup the entire Concrete database.') ?>                    
            </div>
        </div>

        <div class="form-group">
            <div class="col-md-6">
                <label class="control-label"><?php echo t('Product Import File') ?></label>
                <?php echo $concrete_asset_library->file('ccm-import-file', 'import_file', 'Choose File', $importFID) ?>
                <div class="help-block"><?php echo t('Choose the CSV file to import.') ?></div>
            </div>
        </div>
        <div class="form-group">
            <div class="col-md-6">
                <label class="control-label"><?php echo t('Product Images') ?></label>
                <div id="image-drop-zone" style="border: 2px dashed #ccc; border-radius: 4px; padding: 20px; text-align: center; background-color: #f9f9f9; min-height: 150px; position: relative;">
                    <div id="drop-zone-content">
                        <p style="margin: 20px 0 10px 0; font-size: 16px; color: #666;">
                            <i class="fa fa-cloud-upload" style="font-size: 48px; color: #999; display: block; margin-bottom: 10px;"></i>
                            <?php echo t('Drag and drop image files or folders here') ?><br>
                            <small style="color: #999;"><?php echo t('or click to browse files/folders') ?></small>
                        </p>
                        <input type="file" id="image-file-input" multiple accept="image/*" style="display: none;" webkitdirectory>
                        <div style="margin-top: 10px;">
                            <button type="button" id="select-folder-btn" class="btn btn-sm btn-default" style="display: inline-block; margin-right: 5px;">
                                <?php echo t('Select Folder') ?>
                            </button>
                            <button type="button" id="select-files-btn" class="btn btn-sm btn-default" style="display: inline-block;">
                                <?php echo t('Select Files') ?>
                            </button>
                        </div>
                    </div>
                    <div id="upload-progress" style="display: none; margin-top: 15px;">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%"></div>
                        </div>
                        <p id="upload-status" style="margin-top: 10px; font-size: 12px;"></p>
                    </div>
                    <ul id="uploaded-files-list" style="list-style: none; padding: 0; margin-top: 15px; text-align: left;"></ul>
                </div>
                <div class="help-block"><?php echo t('Drop image files or folders here to upload them. All images from folders will be processed recursively. Files with the same filename will be skipped if they already exist in the system.') ?></div>
            </div>
        </div>
        <div class="form-group">
            <div class="col-md-2">
                <label class="control-label"><?php echo t('Field Delimiter') ?></label>
                <?php echo $form->text('delimiter', $config->get('community_store_import.csv.delimiter')) ?>
                <div class="help-block"><?php echo t('Enter tab as \t.') ?></div>
            </div>
            <div class="col-md-2">
                <label class="control-label"><?php echo t('Field Enclosure') ?></label>
                <?php echo $form->text('enclosure', $config->get('community_store_import.csv.enclosure')) ?>
                <div class="help-block"><?php echo t('') ?></div>
            </div>
            <div class="col-md-2">
                <label class="control-label"><?php echo t('Line Length') ?></label>
                <?php echo $form->text('line_length', $config->get('community_store_import.csv.line_length')) ?>
                <div class="help-block"><?php echo t('') ?></div>
            </div>
        </div>
        <div class="form-group">
            <div class="col-md-6">
                <label class="control-label"><?php echo t('Max Run Time') ?></label>
                <?php echo $form->text('max_execution_time', $config->get('community_store_import.max_execution_time')) ?>
                <div class="help-block"><?php echo t('Product import can take some time. Enter the number of seconds to allow the import to run. 1 second per product should be sufficient.') ?></div>
            </div>
        </div>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <button id="import" class='btn btn-primary pull-right'><?php echo t('Save & Import'); ?></button>
        </div>
    </div>
</form>

<script>
$(document).ready(function() {
    $('#import').click(function() {
        return confirm('<?php echo t("Be sure you backup your database before continuing. Are you sure you want to continue?"); ?>');
    });

    var dropZone = $('#image-drop-zone');
    var fileInput = $('#image-file-input');
    var selectFolderBtn = $('#select-folder-btn');
    var selectFilesBtn = $('#select-files-btn');
    var uploadProgress = $('#upload-progress');
    var uploadStatus = $('#upload-status');
    var progressBar = $('.progress-bar');
    var uploadedFilesList = $('#uploaded-files-list');
    var uploadUrl = '<?php echo $view->action('upload_images') ?>';
    var uploadToken = '<?php echo $this->controller->token->generate('upload_images') ?>';

    // Create separate file input for single file selection
    var singleFileInput = $('<input type="file" multiple accept="image/*" style="display: none;">');
    $('body').append(singleFileInput);

    // Folder selection button
    selectFolderBtn.on('click', function(e) {
        e.stopPropagation();
        fileInput.click(); // This will use webkitdirectory
    });

    // File selection button
    selectFilesBtn.on('click', function(e) {
        e.stopPropagation();
        singleFileInput.click();
    });

    // Single file input change (non-directory)
    singleFileInput.on('change', function() {
        handleFiles(this.files);
    });

    // File input change (directory selection)
    fileInput.on('change', function() {
        handleFiles(this.files);
    });

    // Drag and drop handlers
    dropZone.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).css('border-color', '#007bff');
        $(this).css('background-color', '#f0f7ff');
    });

    dropZone.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).css('border-color', '#ccc');
        $(this).css('background-color', '#f9f9f9');
    });

    dropZone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).css('border-color', '#ccc');
        $(this).css('background-color', '#f9f9f9');
        
        var dataTransfer = e.originalEvent.dataTransfer;
        var items = dataTransfer.items;
        
        // Check if any items are directories using File System Access API
        if (items && items.length > 0) {
            var hasDirectories = false;
            var promises = [];
            
            // Check each item to see if it's a directory
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                
                if (item.webkitGetAsEntry) {
                    var entry = item.webkitGetAsEntry();
                    if (entry && entry.isDirectory) {
                        hasDirectories = true;
                        promises.push(processDirectoryEntry(entry));
                    } else if (entry && entry.isFile) {
                        // Handle single file drop - include all files, we'll filter later
                        var file = item.getAsFile();
                        if (file) {
                            promises.push(Promise.resolve([file]));
                        }
                    }
                }
            }
            
            if (hasDirectories || promises.length > 0) {
                // Process directories and files
                uploadStatus.text('<?php echo t("Scanning folders...") ?>');
                uploadProgress.show();
                
                Promise.all(promises).then(function(results) {
                    var allFiles = [];
                    results.forEach(function(files) {
                        allFiles = allFiles.concat(files);
                    });
                    
                    if (allFiles.length === 0) {
                        alert('<?php echo t("No files found in dropped items.") ?>');
                        uploadProgress.hide();
                        return;
                    }
                    
                    // Show progress immediately for scanning
                    uploadProgress.show();
                    uploadedFilesList.empty();
                    
                    // Separate image files from ignored files
                    var imageFiles = [];
                    var ignoredFiles = [];
                    
                    allFiles.forEach(function(file) {
                        if (file.type && file.type.startsWith('image/')) {
                            imageFiles.push(file);
                        } else {
                            ignoredFiles.push(file);
                        }
                    });
                    
                    // Show ignored files immediately
                    if (ignoredFiles.length > 0) {
                        ignoredFiles.forEach(function(file) {
                            uploadedFilesList.append(
                                $('<li>').html('<span class="text-info"><i class="fa fa-info-circle"></i> ' + 
                                '<?php echo t("file ignored") ?>: ' + file.name + '</span>')
                            );
                        });
                    }
                    
                    if (imageFiles.length === 0) {
                        if (ignoredFiles.length > 0) {
                            uploadStatus.html('<?php echo t("No image files found in dropped items.") ?>');
                            progressBar.removeClass('active');
                            progressBar.css('width', '100%');
                        } else {
                            alert('<?php echo t("No image files found.") ?>');
                            uploadProgress.hide();
                        }
                        return;
                    }
                    
                    uploadFiles(imageFiles);
                }).catch(function(error) {
                    console.error('Error processing directory:', error);
                    alert('<?php echo t("Error processing dropped folder. Please try again.") ?>');
                    uploadProgress.hide();
                });
            } else {
                // Fallback to standard file handling
                var files = dataTransfer.files;
                if (files.length > 0) {
                    handleFiles(files);
                }
            }
        } else {
            // Fallback to standard file handling
            var files = dataTransfer.files;
            if (files.length > 0) {
                handleFiles(files);
            }
        }
    });

    /**
     * Recursively process directory entry and collect all files (image and non-image)
     */
    function processDirectoryEntry(entry) {
        return new Promise(function(resolve, reject) {
            var allFiles = []; // Collect all files, not just images
            
            function readDirectory(dirEntry) {
                return new Promise(function(dirResolve, dirReject) {
                    var dirReader = dirEntry.createReader();
                    var entries = [];
                    
                    function readEntries() {
                        dirReader.readEntries(function(results) {
                            if (results.length === 0) {
                                // All entries collected, now process them
                                var promises = [];
                                
                                entries.forEach(function(entry) {
                                    if (entry.isFile) {
                                        promises.push(new Promise(function(fileResolve, fileReject) {
                                            entry.file(function(file) {
                                                // Collect all files, we'll filter later
                                                allFiles.push(file);
                                                fileResolve();
                                            }, fileReject);
                                        }));
                                    } else if (entry.isDirectory) {
                                        promises.push(readDirectory(entry));
                                    }
                                });
                                
                                // Wait for all files and subdirectories to be processed
                                Promise.all(promises).then(function() {
                                    dirResolve();
                                }).catch(dirReject);
                            } else {
                                entries = entries.concat(Array.from(results));
                                readEntries(); // Continue reading more entries
                            }
                        }, dirReject);
                    }
                    
                    readEntries();
                });
            }
            
            // Start reading from the root directory
            readDirectory(entry).then(function() {
                resolve(allFiles);
            }).catch(reject);
        });
    }

    function handleFiles(files) {
        var allFiles = Array.from(files);
        var imageFiles = [];
        var ignoredFiles = [];
        
        allFiles.forEach(function(file) {
            if (file.type && file.type.startsWith('image/')) {
                imageFiles.push(file);
            } else {
                ignoredFiles.push(file);
            }
        });

        if (imageFiles.length === 0 && ignoredFiles.length > 0) {
            alert('<?php echo t("No image files found. Please drop image files only.") ?>');
            return;
        }

        // Show ignored files immediately
        if (ignoredFiles.length > 0) {
            uploadProgress.show();
            uploadedFilesList.empty();
            ignoredFiles.forEach(function(file) {
                uploadedFilesList.append(
                    $('<li>').html('<span class="text-info"><i class="fa fa-info-circle"></i> ' + 
                    '<?php echo t("file ignored") ?>: ' + file.name + '</span>')
                );
            });
        }

        if (imageFiles.length > 0) {
            uploadFiles(imageFiles);
        }
    }

    function uploadFiles(files) {
        uploadProgress.show();
        uploadedFilesList.empty();
        progressBar.css('width', '0%');
        uploadStatus.text('<?php echo t("Uploading...") ?>');

        var total = files.length;
        var uploaded = 0;
        var skipped = 0;
        var failed = 0;

        files.forEach(function(file, index) {
            var formData = new FormData();
            formData.append('file', file);
            formData.append('ccm_token', uploadToken);

            $.ajax({
                url: uploadUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    // Response should already be parsed as JSON due to dataType: 'json'
                    // But handle string responses just in case
                    if (typeof response === 'string') {
                        try {
                            response = JSON.parse(response);
                        } catch (e) {
                            console.error('Failed to parse response:', response, e);
                            failed++;
                            uploadedFilesList.append(
                                $('<li>').html('<span class="text-danger"><i class="fa fa-times"></i> ' + 
                                file.name + ' - <?php echo t("upload failed") ?> (invalid response)')
                            );
                            updateProgress(total, uploaded, skipped, failed);
                            return;
                        }
                    }
                    
                    // Debug: log the response to help diagnose issues
                    console.log('Upload response for', file.name, ':', response, 'Type:', typeof response);
                    // Also try alert for debugging (remove after fixing)
                    if (typeof response === 'boolean' || (response && response.skipped)) {
                        console.warn('Response details:', JSON.stringify(response), 'Keys:', Object.keys(response || {}));
                    }
                    
                    // Handle different response types
                    // Check if response is a boolean (unlikely but possible)
                    if (typeof response === 'boolean') {
                        if (response === true) {
                            // If response is just true, treat as success
                            uploaded++;
                            uploadedFilesList.append(
                                $('<li>').html('<span class="text-success"><i class="fa fa-check"></i> ' + 
                                file.name + ' - <?php echo t("uploaded successfully") ?></span>')
                            );
                        } else {
                            failed++;
                            uploadedFilesList.append(
                                $('<li>').html('<span class="text-danger"><i class="fa fa-times"></i> ' + 
                                file.name + ' - <?php echo t("upload failed") ?></span>')
                            );
                        }
                        updateProgress(total, uploaded, skipped, failed);
                        return;
                    }
                    
                    // Check if response indicates success (success === true means successful response)
                    if (response && typeof response === 'object' && response.success === true) {
                        if (response.skipped === true) {
                            skipped++;
                            uploadedFilesList.append(
                                $('<li>').html('<span class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' + 
                                file.name + ' - <?php echo t("upload ignored, image file exists") ?></span>')
                            );
                        } else {
                            uploaded++;
                            uploadedFilesList.append(
                                $('<li>').html('<span class="text-success"><i class="fa fa-check"></i> ' + 
                                file.name + ' - <?php echo t("uploaded successfully") ?></span>')
                            );
                        }
                    } else {
                        // Response indicates failure (success !== true or has error property)
                        failed++;
                        var errorMsg = '<?php echo t("upload failed") ?>';
                        
                        // Safely extract error message
                        if (response) {
                            if (typeof response === 'object') {
                                // Check for error or message properties (as strings only)
                                if (response.error && typeof response.error === 'string') {
                                    errorMsg = response.error;
                                } else if (response.message && typeof response.message === 'string') {
                                    errorMsg = response.message;
                                }
                                // If skipped is true but success isn't, still show skipped message
                                if (response.skipped === true) {
                                    skipped++;
                                    failed--; // Don't count as failed
                                    uploadedFilesList.append(
                                        $('<li>').html('<span class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' + 
                                        file.name + ' - <?php echo t("upload ignored, image file exists") ?></span>')
                                    );
                                    updateProgress(total, uploaded, skipped, failed);
                                    return;
                                }
                            } else if (typeof response === 'string') {
                                errorMsg = response;
                            }
                        }
                        
                        uploadedFilesList.append(
                            $('<li>').html('<span class="text-danger"><i class="fa fa-times"></i> ' + 
                            file.name + ' - ' + String(errorMsg) + '</span>')
                        );
                    }
                    
                    updateProgress(total, uploaded, skipped, failed);
                },
                error: function(xhr, status, error) {
                    failed++;
                    updateProgress(total, uploaded, skipped, failed);
                    
                    // Try to parse error response if available
                    var errorMsg = '<?php echo t("upload failed") ?>';
                    if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.error) {
                                errorMsg = errorResponse.error;
                            }
                        } catch (e) {
                            // Use default error message
                        }
                    }
                    
                    uploadedFilesList.append(
                        $('<li>').html('<span class="text-danger"><i class="fa fa-times"></i> ' + 
                        file.name + ' - ' + errorMsg + '</span>')
                    );
                }
            });
        });
    }

    function updateProgress(total, uploaded, skipped, failed) {
        var processed = uploaded + skipped + failed;
        var percent = Math.round((processed / total) * 100);
        progressBar.css('width', percent + '%');

        if (processed === total) {
            uploadStatus.html(
                '<?php echo t("Completed") ?>: ' + 
                '<span class="text-success">' + uploaded + ' <?php echo t("uploaded") ?></span>, ' +
                '<span class="text-warning">' + skipped + ' <?php echo t("skipped") ?></span>, ' +
                '<span class="text-danger">' + failed + ' <?php echo t("failed") ?></span>'
            );
            progressBar.removeClass('active');
        } else {
            uploadStatus.text('<?php echo t("Uploading") ?>... ' + processed + ' / ' + total);
        }
    }
});
</script>
