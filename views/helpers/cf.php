<?php
/**
 * Cloud Front or CDN asset helper
 *
 * Allows to load assets from remote server(s) in production mode.
 * Supports:
 *  - Multiple hosts (for faster load time)
 *  - Caching and timestamps
 *  - Serving via SSL
 *
 */

App::uses('AppHelper', 'View/Helper');

class CfHelper extends AppHelper {

    /**
     * Let's load required helpers
     *
     */
    public $helpers = array('Html');

    /**
     * Configuration
     *
     *  - assetHost
     *
     *      Where are the assets hosted?
     *      Possible options: 'assets.example.com', if you only have one host
     *      Or: 'assets%d.example.com', if you have multiple hosts. %d gets replaced with host number
     *
     *  - numHostsMin & numHostsMax
     *
     *      If above is 'assets%d.example.com' will generate host names from 0 - 3
     *      i.e. assets0.example.com
     *
     *  - sslHost
     *
     *      Serving assets via SSL is slow, let's use a unique host (for better caching)
     *
     *  - imgDir
     *
     *      Where are the images relative to web root (local should mirror remote)
     *      Try to stick to cake conventions.
     *
     *  - jsDir
     *      Where are the JS files relative to web root (local should mirror remote)
     *      Try to stick to cake conventions.
     *
     *  - cssDir
     *      Where are the CSS files relative to web root (local should mirror remote)
     *      Try to stick to cake conventions.
     *
     *  - assetDir
     *
     *      Will set asset directory depending on the asset type (css, js, img)
     *
     *  - forceTimestamp
     *
     *      We should really force the timestamp to improve caching.
     *      Trun on the option in core.php
     *
     *  - remoteCompressedFiles
     *
     *      If Cloudfront origin is S3 then we can supply the user
     *      with compressed files if we add them to S3 first and then
     *      change the file that is requested.
     *      To make this work we need a gzip version of each css and
     *      js file in the format cake.gz.css.
     *      If this is turned on the app will check that the agent
     *      accepts gzip encoded files and will server the gz version
     *      instead.
     *      This feature is not required if the origin of cloudfront
     *      is your website.
     *
     *      Thx to redthor ! (https://github.com/redthor)
     */
    public $configuration = array(
        'assetHost' => 'assets%d.example.com',
        'numHostsMin' => 0,
        'numHostsMax' => 3,
        'modulo' => 1,
        'sslHost' => 'sslhost.example.com',
        'imgDir' => 'img',
        'jsDir' => 'js',
        'cssDir' => 'css',
        'assetDir' => null,
        'forceTimestamp' => false,
        'remoteCompressedFiles' => false
    );

    public function __construct(View $view, $configuration = array()) {
        parent::__construct($view, $configuration);

        if (!empty($configuration)) {
            $this->configuration = array_merge($this->configuration, $configuration);
        }

        $this->configuration['modulo'] = $this->configuration['numHostsMax'];
        if ($this->configuration['numHostsMin'] == 0) {
            $this->configuration['modulo'] = $this->configuration['numHostsMax'] + 1;
        }

        $useCompressedFiles = $this->configuration['remoteCompressedFiles'] &&
                              (strpos(env('HTTP_ACCEPT_ENCODING'), 'gzip') !== false) &&
                              Configure::read('debug') == 0;

        $this->configuration['remoteCompressedFiles'] = $useCompressedFiles;

        if (Configure::read('debug') > 0) {
            $this->configuration['assetHost'] = rtrim(env('HTTP_HOST') . Router::url('/'), '/');
            $this->configuration['sslHost'] = rtrim(env('HTTP_HOST') . Router::url('/'), '/');
        }
    }

    /**
     * Are we forcing the timestamp (based on core.php setting)?
     * (We really, really should)
     *
     */
    public function beforeRender() {

        if ((Configure::read('Asset.timestamp') == true && Configure::read('debug') == 0) || Configure::read('Asset.timestamp') === 'force') {
            $this->configuration['forceTimestamp'] = true;
        }
    }

    /**
     * Return image path/URL either remote or local based on the debug level
     *
     */
    public function image($assets, $options = array(), $src = false) {

        $this->setAssetDir($this->configuration['imgDir']);

        if ($assets[0] == '/') {
            $this->setAssetDir(null);
        }

        if ($src == true) {
            return $this->setAssetPath($assets);
        }

        return $this->Html->image($this->setAssetPath($assets), $options);
    }

    /**
     * Return JS link path/URL either remote or local based on the debug level
     *
     */
    public function script($assets, $options = array()) {

        $this->setAssetDir($this->configuration['jsDir']);

        return $this->Html->script($this->setAssetPath($assets, true), $options);
    }

    /**
     * Return CSS link path/URL either remote or local based on the debug level
     *
     */
    public function css($assets, $rel = null, $options = array()) {

        $this->setAssetDir($this->configuration['cssDir']);

        return $this->Html->css($this->setAssetPath($assets, true), $rel, $options);
    }

    /**
     * Prepare the asset path or URL and tack on the timestamp (if $this->forceTimestamp == TRUE)
     * Works for arrays of assets (like with JS or CSS) or single files
     *
     */
    private function setAssetPath($assets = null, $tryGzVersion = false) {

        if ($assets) {
            if (is_array($assets)) {
                $size = count($assets);
                for ($i = 0; $i < $size; $i++) {
                    $assets[$i] = $this->pathPrep($assets[$i]) . $this->gzName($assets[$i], $tryGzVersion) . $this->getAssetTimestamp();
                }
            } else {
                return $this->pathPrep($assets) .  $this->gzName($assets, $tryGzVersion) . $this->getAssetTimestamp();
            }
        }

        return $assets;
    }

    /**
     * Return the gzip filename of a file
     * myapp.css -> myapp.gz.css
     *
     */
    private function gzName($assetFileName, $tryGzVersion = false) {

        if ($tryGzVersion && $this->configuration['remoteCompressedFiles']) {
            $assetParts = pathinfo($assetFileName);
            return $assetParts['filename'] . '.gz.' . $assetParts['extension'];
        }

        return $assetFileName;
    }

    /**
     * Build asset URL
     *
     */
    private function pathPrep($assets) {

        return $this->getProtocol() . $this->getAssetHost($assets) . $this->configuration['assetDir'];
    }

    /**
     * Set proper asset directory (relative to web root), based on the asset type
     *
     */
    private function setAssetDir($dir = null) {

        if ($dir) {
            return $this->configuration['assetDir'] = '/' . $dir . '/';
        }

        return $this->configuration['assetDir'] = null;
    }

    /**
     * Get asset timestamp
     * We assume that local filesystem has the same assets (and dir structure) as the remote one
     * (It really should to make managment and version controll painless)
     *
     */
    private function getAssetTimestamp() {

        if ($this->configuration['forceTimestamp'] == true) {
            return '?' . @filemtime(str_replace('/', DS, WWW_ROOT . $this->configuration['assetDir']));
        }

        return false;
    }

    /**
     * HTTPS or not?
     *
     */
    private function getProtocol() {

        if (env('HTTPS')) {
            return 'https://';
        }

        return 'http://';
    }

    /**
     * Return host name.
     * Options:
     * - multiple hosts (generate random host names based on $this->numHostsMin and $this->numHostsMax
     * - single host
     * - SSL host
     *
     */
    private function getAssetHost($assets) {

        if (!env('HTTPS')) {
            if (strstr($this->configuration['assetHost'], '%d')) {
                $randomHost = (md5($assets) % $this->configuration['modulo']);
                //$randomHost = rand($this->configuration['numHostsMin'], $this->configuration['numHostsMax']);
                return sprintf($this->configuration['assetHost'], $randomHost);
            } else {
                return $this->configuration['assetHost'];
            }
        } elseif (env('HTTPS')) {
            return $this->configuration['sslHost'];
        }
    }
}
