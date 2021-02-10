<?php

namespace PragmaRX\Support\GeoIp;

class Updater
{
    protected $databaseFileGzipped;

    protected $databaseFile;

    protected $md5File;

    protected $messages = [];

    /**
     * Add a message.
     *
     * @param $string
     */
    private function addMessage($string)
    {
        $this->messages[] = $string;
    }

    protected function databaseIsUpdated($geoDbFileUrl, $geoDbMd5Url, $destinationPath)
    {
        $destinationGeoDbFile = ($destinationPath . DIRECTORY_SEPARATOR . $this->getDbFileName());

        $this->md5File = $this->getHTTPFile($geoDbMd5Url, $destinationPath . DIRECTORY_SEPARATOR, $this->getMd5FileName());

        if (!file_exists($destinationGeoDbFile)) {
            return false;
        }

        if ($updated = file_get_contents($this->md5File) == md5_file($destinationGeoDbFile)) {
            $this->addMessage('Database is already updated.');
        }

        return $updated;
    }

    /**
     * Download gzipped database, unzip and check md5.
     *
     * @param $destinationPath
     * @param $geoDbUrl
     * @return bool
     */
    protected function downloadGzipped($destinationPath, $geoDbUrl)
    {
        if (!$this->databaseFileGzipped = $this->getHTTPFile(
            $geoDbUrl,
            ($destination = $destinationPath . DIRECTORY_SEPARATOR),
            $this->getDbFileName()
        )) {
            $this->addMessage("Unable to download file {$geoDbUrl} to {$destination}.");
        }


        $this->databaseFile = $this->dezipGzFile($destinationPath . DIRECTORY_SEPARATOR . $this->getDbFileName());

        return $this->md5Match();
    }

    private function getDbFileName()
    {
        return 'GeoLite2-City.mmdb.tar.gz';
    }

    private function getMd5FileName()
    {
        return 'GeoLite2-City.md5';
    }

    /**
     * Get messages.
     *
     * @return mixed
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Make directory.
     *
     * @param $destinationPath
     * @return bool
     */
    protected function makeDir($destinationPath)
    {
        return file_exists($destinationPath) || mkdir($destinationPath, 0770, true);
    }

    /**
     * Compare MD5s.
     *
     * @return bool
     */
    private function md5Match()
    {

        if (!$match = md5_file($this->databaseFileGzipped) == file_get_contents($this->md5File)) {
            $this->addMessage("MD5 is not matching for {$this->databaseFile} and {$this->md5File}.");

            return false;
        }

        $this->addMessage("Database successfully downloaded to {$this->databaseFile}.");

        return true;
    }

    /**
     * Remove .gzip extension from file.
     *
     * @param $filePath
     * @return mixed
     */
    protected function removeGzipExtension($filePath)
    {
        $file = $filePath;
        $file = str_replace('.gz', '', $file);
        $file = str_replace('.tar', '', $file);
        return $file;
    }

    /**
     * Download and update GeoIp database.
     *
     * @param $destinationPath
     * @param null $geoDbUrl
     * @param null $geoDbMd5Url
     * @return bool
     */
    public function updateGeoIpFiles($destinationPath, $geoDbUrl, $geoDbMd5Url)
    {
        $geoDbMd5Url = str_replace('.sha256', '.md5', $geoDbMd5Url);
        if ($this->databaseIsUpdated($this->getDbFileName(), $geoDbMd5Url, $destinationPath)) {
            return true;
        }
        if ($this->downloadGzipped($destinationPath, $geoDbUrl)) {
            return true;
        }

        $this->addMessage("Unknown error downloading {$geoDbUrl}.");

        return false;
    }

    /**
     * Read url to file.
     *
     * @param $uri
     * @param $destinationPath
     * @return bool|string
     */
    protected function getHTTPFile($uri, $destinationPath, $filename)
    {
        set_time_limit(360);

        if (!$this->makeDir($destinationPath)) {
            return false;
        }

        $fileWriteName = $destinationPath . $filename;


        if (($fileRead = @fopen($uri, "rb")) === false) {
            $this->addMessage("Unable to open {$uri} (read).");

            return false;
        }

        if (($fileWrite = @fopen($fileWriteName, 'wb')) === false) {
            $this->addMessage("Unable to open {$fileWriteName} (write).");

            return false;
        }


        while (!feof($fileRead)) {
            $content = @fread($fileRead, 1024 * 16);
            $success = fwrite($fileWrite, $content);

            if ($success === false) {
                $this->addMessage("Error downloading file {$uri} to {$fileWriteName}.");

                return false;
            }
        }

        fclose($fileWrite);

        fclose($fileRead);

        return $fileWriteName;
    }

    protected function glob_recursive($pattern, $flags = 0)
    {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, $this->glob_recursive($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }

    /**
     * Extract gzip file.
     *
     * @param $filePath
     * @return bool|mixed
     */
    protected function dezipGzFile($filePath)
    {
        ini_set('memory_limit', '256M');

        $buffer_size = 8192; // read 8kb at a time

        $out_file_name = $this->removeGzipExtension($filePath);

        try {
            $phar = new \PharData($filePath);
            $phar->extractTo(dirname($out_file_name), null, true); // extract all files

            if (file_exists($out_file_name)) {
                unlink($out_file_name);
            }
            foreach ($this->glob_recursive(dirname($out_file_name) . '/*.mmdb') as $item) {
                rename($item, $out_file_name);
            }
        } catch (Exception $e) {
            // handle errors
        }

        //        $fileRead = gzopen($filePath, 'rb');
        //
        //        $fileWrite = fopen($out_file_name, 'wb');
        //
        //        if ($fileRead === false || $fileWrite === false) {
        //            $this->addMessage("Unable to extract gzip file {$filePath} to {$out_file_name}.");
        //
        //            return false;
        //        }
        //
        //        while (!gzeof($fileRead)) {
        //            $success = fwrite($fileWrite, gzread($fileRead, $buffer_size));
        //
        //            if ($success === false) {
        //                $this->addMessage("Error degzipping file {$filePath} to {$out_file_name}.");
        //
        //                return false;
        //            }
        //        }
        //
        //        // Files are done, close files
        //        fclose($fileWrite);
        //
        //        gzclose($fileRead);

        return $out_file_name;
    }
}
