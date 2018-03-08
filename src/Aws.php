<?php

namespace Nails\Cdn\Driver;

use Aws\Common\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Nails\Cdn\Exception\DriverException;
use Nails\Environment;

class Aws extends Local
{
    /**
     * The S3 SDK
     * @var S3Client
     */
    protected $oSdk;

    /**
     * The S3 bucket where items will be stored (not to be confused with internal buckets)
     * @var string
     */
    protected $sS3Bucket;

    // --------------------------------------------------------------------------

    /**
     * Returns an instance of the AWS S3 SDK
     * @return S3Client
     * @throws DriverException
     */
    protected function sdk()
    {
        if (empty($this->oSdk)) {

            $oCredentials = new Credentials(
                $this->getSetting('access_key'),
                $this->getSetting('access_secret')
            );

            $this->oSdk = S3Client::factory([
                'credentials' => $oCredentials,
            ]);

            $this->sS3Bucket = $this->getBucket();
        }

        return $this->oSdk;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the Google Storage bucket for this environment
     * @return string
     * @throws DriverException
     */
    protected function getBucket()
    {
        if (empty($this->sS3Bucket)) {
            $aBuckets = json_decode($this->getSetting('buckets'), true);
            if (empty($aBuckets)) {
                throw new DriverException('S3 Buckets have not been defined.');
            } elseif (empty($aBuckets[Environment::get()])) {
                throw new DriverException('No bucket defined for the ' . Environment::get() . ' environment.');
            } else {
                $this->sS3Bucket = $aBuckets[Environment::get()];
            }
        }

        return $this->sS3Bucket;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the requested URI and replaces {{bucket}} with the S3 bucket being used
     *
     * @param $sUriType
     *
     * @return string
     */
    protected function getUri($sUriType)
    {
        return str_replace('{{bucket}}', $this->getBucket(), $this->getSetting('uri_' . $sUriType));
    }

    // --------------------------------------------------------------------------

    /**
     * OBJECT METHODS
     */

    /**
     * Creates a new object
     *
     * @param  \stdClass $oData Data to create the object with
     *
     * @return boolean
     */
    public function objectCreate($oData)
    {
        $sBucket       = !empty($oData->bucket->slug) ? $oData->bucket->slug : '';
        $sFilenameOrig = !empty($oData->filename) ? $oData->filename : '';
        $sFilename     = strtolower(substr($sFilenameOrig, 0, strrpos($sFilenameOrig, '.')));
        $sExtension    = strtolower(substr($sFilenameOrig, strrpos($sFilenameOrig, '.')));
        $sSource       = !empty($oData->file) ? $oData->file : '';
        $sMime         = !empty($oData->mime) ? $oData->mime : '';
        $sName         = !empty($oData->name) ? $oData->name : 'file' . $sExtension;

        // --------------------------------------------------------------------------

        try {

            //  Create "normal" version
            $this->sdk()->putObject([
                'Bucket'      => $this->sS3Bucket,
                'Key'         => $sBucket . '/' . $sFilename . $sExtension,
                'SourceFile'  => $sSource,
                'ContentType' => $sMime,
                'ACL'         => 'public-read',
            ]);

            //  Create "download" version
            $this->sdk()->copyObject([
                'Bucket'             => $this->sS3Bucket,
                'CopySource'         => $this->sS3Bucket . '/' . $sBucket . '/' . $sFilename . $sExtension,
                'Key'                => $sBucket . '/' . $sFilename . '-download' . $sExtension,
                'ContentType'        => 'application/octet-stream',
                'ContentDisposition' => 'attachment; filename="' . str_replace('"', '', $sName) . '" ',
                'MetadataDirective'  => 'REPLACE',
                'ACL'                => 'public-read',
            ]);

            return true;

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [objectCreate]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether an object exists or not
     *
     * @param  string $sFilename The object's filename
     * @param  string $sBucket   The bucket's slug
     *
     * @return boolean
     */
    public function objectExists($sFilename, $sBucket)
    {
        return $this->sdk()->doesObjectExist($sBucket, $sFilename);
    }

    // --------------------------------------------------------------------------

    /**
     * Destroys (permanently deletes) an object
     *
     * @param  string $sObject The object's filename
     * @param  string $sBucket The bucket's slug
     *
     * @return boolean
     */
    public function objectDestroy($sObject, $sBucket)
    {
        try {

            $sFilename  = strtolower(substr($sObject, 0, strrpos($sObject, '.')));
            $sExtension = strtolower(substr($sObject, strrpos($sObject, '.')));
            $aOptions   = [
                'Bucket'  => $this->sS3Bucket,
                'Objects' => [
                    ['Key' => $sBucket . '/' . $sFilename . $sExtension],
                    ['Key' => $sBucket . '/' . $sFilename . '-download' . $sExtension],
                ],
            ];

            $this->sdk()->deleteObjects($aOptions);
            return true;

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [objectDestroy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a local path for an object
     *
     * @param  string $sBucket   The bucket's slug
     * @param  string $sFilename The filename
     *
     * @return mixed             String on success, false on failure
     */
    public function objectLocalPath($sBucket, $sFilename)
    {
        //  Do we have the original sourcefile?
        $sExtension = strtolower(substr($sFilename, strrpos($sFilename, '.')));
        $sFilename  = strtolower(substr($sFilename, 0, strrpos($sFilename, '.')));
        $sSrcFile   = DEPLOY_CACHE_DIR . $sBucket . '-' . $sFilename . '-SRC' . $sExtension;

        //  Check filesystem for source file
        if (file_exists($sSrcFile)) {

            //  Yup, it's there, so use it
            return $sSrcFile;

        } else {

            //  Doesn't exist, attempt to fetch from S3
            try {

                $this->sdk()->getObject([
                    'Bucket' => $this->sS3Bucket,
                    'Key'    => $sBucket . '/' . $sFilename . $sExtension,
                    'SaveAs' => $sSrcFile,
                ]);

                return $sSrcFile;

            } catch (S3Exception $e) {

                //  Clean up
                if (file_exists($sSrcFile)) {
                    unlink($sSrcFile);
                }

                //  Note the error
                $this->setError('AWS-SDK EXCEPTION: [objectLocalPath]: ' . $e->getMessage());
                return false;
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * BUCKET METHODS
     */

    /**
     * Creates a new bucket
     *
     * @param  string $sBucket The bucket's slug
     *
     * @return boolean
     */
    public function bucketCreate($sBucket)
    {
        //  Attempt to create a 'folder' object on S3
        if (!$this->sdk()->doesObjectExist($this->sS3Bucket, $sBucket . '/')) {

            try {

                $this->sdk()->putObject([
                    'Bucket' => $this->sS3Bucket,
                    'Key'    => $sBucket . '/',
                    'Body'   => '',
                ]);

                return true;

            } catch (\Exception $e) {
                $this->setError('AWS-SDK EXCEPTION: [bucketCreate]: ' . $e->getMessage());
                return false;
            }

        } else {

            //  Bucket already exists.
            return true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an existing bucket
     *
     * @param  string $sBucket The bucket's slug
     *
     * @return boolean
     */
    public function bucketDestroy($sBucket)
    {
        //  @todo - consider the implications of bucket deletion; maybe prevent deletion of non-empty buckets
        dumpanddie('@todo');
        try {

            $this->sdk()->deleteMatchingObjects($this->sS3Bucket, $sBucket . '/');
            return true;

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [bucketDestroy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * URL GENERATOR METHODS
     */

    /**
     * Generate the correct URL for serving a file direct from the file system
     *
     * @param  string $sObject The object to serve
     * @param  string $sBucket The bucket to serve from
     *
     * @return string
     */
    public function urlServeRaw($sObject, $sBucket)
    {
        return $this->urlServe($sObject, $sBucket);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the scheme of 'serve' URLs
     *
     * @param  boolean $bForceDownload Whether or not to force download
     *
     * @return string
     */
    public function urlServeScheme($bForceDownload = false)
    {
        $sUrl = addTrailingSlash($this->getUri('serve')) . '{{bucket}}/';

        /**
         * If we're forcing the download we need to reference a slightly different file.
         * On upload two instances were created, the "normal" streaming type one and
         * another with the appropriate content-types set so that the browser downloads
         * as opposed to renders it
         */
        if ($bForceDownload) {
            $sUrl .= '{{filename}}-download{{extension}}';
        } else {
            $sUrl .= '{{filename}}{{extension}}';
        }

        return $this->urlMakeSecure($sUrl);
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a properly hashed expiring url
     *
     * @param  string  $sBucket        The bucket which the image resides in
     * @param  string  $sObject        The object to be served
     * @param  integer $iExpires       The length of time the URL should be valid for, in seconds
     * @param  boolean $bForceDownload Whether to force a download
     *
     * @return string
     */
    public function urlExpiring($sObject, $sBucket, $iExpires, $bForceDownload = false)
    {
        //  @todo - consider generating a CloudFront expiring/signed URL instead.
        return parent::urlExpiring($sObject, $sBucket, $iExpires, $bForceDownload);
    }
}