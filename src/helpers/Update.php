<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\helpers;

use Craft;
use craft\db\DbBackup;
use craft\enums\PatchManifestFileAction;
use yii\base\Exception;

/**
 * Update helper.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Update
{
    // Properties
    // =========================================================================

    /**
     * @var
     */
    private static $_manifestData;

    // Public Methods
    // =========================================================================

    /**
     * @param array  $manifestData
     * @param string $handle
     *
     * @return void
     */
    public static function rollBackFileChanges($manifestData, $handle)
    {
        foreach ($manifestData as $row) {
            if (static::isManifestVersionInfoLine($row)) {
                continue;
            }

            if (static::isManifestMigrationLine($row)) {
                continue;
            }

            $rowData = explode(';', $row);

            if ($handle == 'craft') {
                $directory = Craft::$app->getPath()->getAppPath();
            } else {
                $directory = Craft::$app->getPath()->getPluginsPath().'/'.$handle;
            }

            $file = Io::normalizePathSeparators($directory.'/'.$rowData[0]);

            // It's a folder
            if (static::isManifestLineAFolder($file)) {
                $folderPath = static::cleanManifestFolderLine($file);

                if (Io::folderExists($folderPath.'.bak')) {
                    Io::rename($folderPath, $folderPath.'-tmp');
                    Io::rename($folderPath.'.bak', $folderPath);
                    Io::clearFolder($folderPath.'-tmp');
                    Io::deleteFolder($folderPath.'-tmp');
                }
            } // It's a file.
            else {
                if (Io::fileExists($file.'.bak')) {
                    Io::rename($file.'.bak', $file);
                }
            }
        }
    }

    /**
     * Rolls back any changes made to the DB during the update process.
     *
     * @param $backupPath
     *
     * @return boolean
     */
    public static function rollBackDatabaseChanges($backupPath)
    {
        $fileName = $backupPath.'.sql';
        $fullBackupPath = Craft::$app->getPath()->getDbBackupPath().'/'.$fileName;

        // Make sure we're constrained to the backups folder.
        if (Path::ensurePathIsContained($fileName)) {
            if (Craft::$app->getDb()->restore($fullBackupPath)) {
                return true;
            } else {
                Craft::error('There was a problem restoring the database backup.', __METHOD__);
            }
        } else {
            Craft::warning('Someone tried to restore a database from outside of the Craft backups folder: '.$fullBackupPath, __METHOD__);
        }

        return false;
    }

    /**
     * @param array  $manifestData
     * @param string $sourceTempFolder
     * @param string $handle
     *
     * @return boolean
     */
    public static function doFileUpdate($manifestData, $sourceTempFolder, $handle)
    {
        if ($handle == 'craft') {
            $destDirectory = Craft::$app->getPath()->getAppPath();
            $sourceFileDirectory = '/app';
        } else {
            $destDirectory = Craft::$app->getPath()->getPluginsPath().'/'.$handle;
            $sourceFileDirectory = '';
        }

        try {
            foreach ($manifestData as $row) {
                if (static::isManifestVersionInfoLine($row)) {
                    continue;
                }

                $folder = false;
                $rowData = explode(';', $row);

                if (static::isManifestLineAFolder($rowData[0])) {
                    $folder = true;
                    $tempPath = static::cleanManifestFolderLine($rowData[0]);
                } else {
                    $tempPath = $rowData[0];
                }

                $destFile = Io::normalizePathSeparators($destDirectory.'/'.$tempPath);
                $sourceFile = Io::getRealPath(Io::normalizePathSeparators(rtrim($sourceTempFolder, '/').$sourceFileDirectory.'/'.$tempPath));

                switch (trim($rowData[1])) {
                    // update the file
                    case PatchManifestFileAction::Add: {
                        if ($folder) {
                            Craft::info('Updating folder: '.$destFile, __METHOD__);

                            // Invalidate any existing files
                            if (function_exists('opcache_invalidate') && Io::folderExists($destFile)) {
                                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destFile));

                                foreach ($iterator as $oldFile) {
                                    /** @var \SplFileInfo $file */
                                    if ($oldFile->isFile()) {
                                        opcache_invalidate($oldFile, true);
                                    }
                                }
                            }

                            $tempFolder = rtrim($destFile, '/').StringHelper::UUID();
                            $tempTempFolder = rtrim($destFile, '/').'-tmp';

                            Io::createFolder($tempFolder);
                            Io::copyFolder($sourceFile, $tempFolder);
                            Io::rename($destFile, $tempTempFolder);
                            Io::rename($tempFolder, $destFile);
                            Io::clearFolder($tempTempFolder);
                            Io::deleteFolder($tempTempFolder);
                        } else {
                            Craft::info('Updating file: '.$destFile, __METHOD__);

                            // Invalidate opcache
                            if (function_exists('opcache_invalidate') && Io::fileExists($destFile)) {
                                opcache_invalidate($destFile, true);
                            }

                            Io::copyFile($sourceFile, $destFile);
                        }

                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Craft::error('Error updating files: '.$e->getMessage(), __METHOD__);
            Update::rollBackFileChanges($manifestData, $handle);

            return false;
        }

        return true;
    }

    /**
     * @param $line
     *
     * @return boolean
     */
    public static function isManifestVersionInfoLine($line)
    {
        return strncmp($line, '##', 2) === 0;
    }

    /**
     * Returns the local version number from the given manifest file.
     *
     * @param $manifestData
     *
     * @return boolean|string
     */
    public static function getLocalVersionFromManifest($manifestData)
    {
        if (!static::isManifestVersionInfoLine($manifestData[0])) {
            return false;
        }

        preg_match('/^##(.*);/', $manifestData[0], $matches);

        return $matches[1];
    }

    /**
     * Return true if line is a manifest migration line.
     *
     * @param $line
     *
     * @return boolean
     */
    public static function isManifestMigrationLine($line)
    {
        if (StringHelper::contains($line, 'migrations/')) {
            return true;
        }

        return false;
    }

    /**
     * Returns the relevant lines from the update manifest file starting with the current local version.
     *
     * @param string $manifestDataPath
     * @param string $handle
     *
     * @return array
     * @throws Exception if there was a problem reading the update manifest data
     */
    public static function getManifestData($manifestDataPath, $handle)
    {
        if (static::$_manifestData == null) {
            $fullPath = rtrim($manifestDataPath, '/').'/'.$handle.'_manifest';
            if (Io::fileExists($fullPath)) {
                // get manifest file
                $manifestFileData = Io::getFileContents($fullPath, true);

                if ($manifestFileData === false) {
                    throw new Exception('There was a problem reading the update manifest data');
                }

                // Remove any trailing empty newlines
                if ($manifestFileData[count($manifestFileData) - 1] == '') {
                    array_pop($manifestFileData);
                }

                $manifestData = array_map('trim', $manifestFileData);
                $updateModel = Craft::$app->getUpdates()->getUpdates();

                $localVersion = null;

                if ($handle == 'craft') {
                    $localVersion = $updateModel->app->localVersion;
                } else {
                    foreach ($updateModel->plugins as $plugin) {
                        if (strtolower($plugin->class) == $handle) {
                            $localVersion = $plugin->localVersion;
                            break;
                        }
                    }
                }

                // Only use the manifest data starting from the local version
                for ($counter = 0; $counter < count($manifestData); $counter++) {
                    if (StringHelper::contains($manifestData[$counter], '##'.$localVersion)) {
                        break;
                    }
                }

                $manifestData = array_slice($manifestData, $counter);
                static::$_manifestData = $manifestData;
            }
        }

        return static::$_manifestData;
    }

    /**
     * @param $uid
     *
     * @return string
     */
    public static function getUnzipFolderFromUID($uid)
    {
        return Craft::$app->getPath()->getTempPath().'/'.$uid;
    }

    /**
     * @param $uid
     *
     * @return string
     */
    public static function getZipFileFromUID($uid)
    {
        return Craft::$app->getPath()->getTempPath().'/'.$uid.'.zip';
    }

    /**
     * @param $line
     *
     * @return boolean
     */
    public static function isManifestLineAFolder($line)
    {
        if (mb_substr($line, -1) == '*') {
            return true;
        }

        return false;
    }

    /**
     * @param $line
     *
     * @return string
     */
    public static function cleanManifestFolderLine($line)
    {
        $line = rtrim($line, '*');

        return rtrim($line, '/');
    }
}