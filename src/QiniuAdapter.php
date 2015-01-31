<?php namespace Checksnug\Upload\Adapter;

use League\Flysystem\Util;
use League\Flysystem\Config;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

define('Qiniu_RSF_EOF', 'EOF');

function Qiniu_RSF_ListPrefix(
    $self, $bucket, $prefix = '', $marker = '', $limit = 0) // => ($items, $markerOut, $err)
{
    global $QINIU_RSF_HOST;

    $query = array('bucket' => $bucket);
    if (!empty($prefix)) {
        $query['prefix'] = $prefix;
    }
    if (!empty($marker)) {
        $query['marker'] = $marker;
    }
    if (!empty($limit)) {
        $query['limit'] = $limit;
    }

    $url =  $QINIU_RSF_HOST . '/list?' . http_build_query($query);
    list($ret, $err) = Qiniu_Client_Call($self, $url);
    if ($err !== null) {
        return array(null, '', $err);
    }

    $items = $ret['items'];
    if (empty($ret['marker'])) {
        $markerOut = '';
        $err = Qiniu_RSF_EOF;
    } else {
        $markerOut = $ret['marker'];
    }
    return array($items, $markerOut, $err);
}


class QiniuAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    public $bucket;
    public $domain;
    private $_client;

    public function __construct($bucket, $accessKey, $accessSecret, $domain = null)
    {
        $this->bucket = $bucket;
        \Qiniu_SetKeys($accessKey, $accessSecret);
        $this->domain = $domain === null ? $this->bucket . '.qiniudn.com' : $domain;
    }
    /**
     * Check whether a file is present
     *
     * @param   string   $path
     * @return  boolean
     */
    public function has($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Write a file
     *
     * @param $path
     * @param $contents
     * @param null $config
     * @return array|bool
     */
    public function write($path, $contents, Config $config)
    {
        return $this->update($path, $contents, $config);
    }

    /**
     * Write using a stream
     *
     * @param $path
     * @param $resource
     * @param null $config
     * @return array|bool
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->updateStream($path, $resource, $config);
    }

    /**
     * Update a file
     *
     * @param   string       $path
     * @param   string       $contents
     * @param   mixed        $config   Config object or visibility setting
     * @return  array|bool
     */
    public function update($path, $contents, Config $config)
    {
        list($ret, $err) = \Qiniu_RS_Put($this->getClient(), $this->bucket, $path, $contents, null);
        if ($err !== null) {
            return false;
        }
        $mimetype = Util::guessMimeType($path, $contents);
        return compact('mimetype', 'path');
    }

    /**
     * Update a file using a stream
     *
     * @param   string    $path
     * @param   resource  $resource
     * @param   mixed     $config   Config object or visibility setting
     * @return  array|bool
     */
    public function updateStream($path, $resource, Config $config)
    {
        //fseek($resource, 0);
        $size = Util::getStreamSize($resource);
        list($ret, $err) = \Qiniu_RS_Rput($this->getClient(), $this->bucket, $path, $resource, $size, null);
        //fseek($resource, 0);
        if ($err !== null) {
            return false;
        }
        return compact('size', 'path');
    }

    /**
     * Read a file
     *
     * @param   string  $path
     * @return  array|bool
     */
    public function read($path)
    {
        $contents = stream_get_contents($this->readStream($path)['stream']);
        return compact('contents', 'path');
    }

    /**
     * Get a read-stream for a file
     *
     * @param $path
     * @return array|bool
     */
    public function readStream($path)
    {
        $stream = fopen($this->getPrivateUrl($path), 'r');
        return compact('stream', 'path');
    }

    /**
     * Rename a file
     *
     * @param $path
     * @param $newpath
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $err = \Qiniu_RS_Move($this->getClient(), $this->bucket, $path, $this->bucket, $newpath);
        return $err === null;
    }

    /**
     * Copy a file
     *
     * @param $path
     * @param $newpath
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $err = \Qiniu_RS_Copy($this->getClient(), $this->bucket, $path, $this->bucket, $newpath);
        return $err === null;
    }

    /**
     * Delete a file
     *
     * @param $path
     * @return bool
     */
    public function delete($path)
    {
        $err = \Qiniu_RS_Delete($this->getClient(), $this->bucket, $path);
        return $err === null;
    }

    /**
     * List contents of a directory
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $files = [];
        foreach($this->listDirContents($directory) as $k => $file) {
            $pathInfo = pathinfo($file['key']);
            $files[] = array_merge($pathInfo, $this->normalizeData($file), [
                'type' => isset($pathInfo['extension']) ? 'file' : 'dir',
            ]);
        }
        return $files;
    }

    public function getRawMetadata($path)
    {
        list($ret, $err) = \Qiniu_RS_Stat($this->getClient(), $this->bucket, $path);
        if ($err !== null) {
            return false;
        }
        return $ret;
    }

    public function getImageInfo($path)
    {
        $json = file_get_contents("http://{$this->domain}/{$path}?imageInfo");
        return json_decode($json, true);
    }

    /**
     * Get the metadata of a file
     *
     * @param $path
     * @return array
     */
    public function getMetadata($path)
    {
        $ret = $this->getRawMetadata($path);
        if ($ret === false) {
            return false;
        }
        $ret['key'] = $path;
        return $this->normalizeData($ret);
    }

    /**
     * Get the size of a file
     *
     * @param $path
     * @return array
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file
     *
     * @param $path
     * @return array
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file
     *
     * @param $path
     * @return array
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Create a directory
     * 七牛无目录概念. 直接创建成功
     * @param   string       $dirname directory name
     * @param   array|Config $options
     *
     * @return  bool
     */
    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname];
    }

    /**
     * Delete a directory
     * 七牛无目录概念. 目前实现方案是.列举指定目录资源.批量删除
     * @param $dirname
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $item = $this->listDirContents($dirname);
        $enties = array_map(function($file) {
            return new \Qiniu_RS_EntryPath($this->bucket, $file['key']);
        }, $item);
        list($ret, $err) = \Qiniu_RS_BatchDelete($this->getClient(), $enties);
        return $err === null;
    }

    protected function normalizeData($file)
    {
        return [
            'type' => 'file',
            'path' => $file['key'],
            'size' => $file['fsize'],
            'mimetype' => $file['mimeType'],
            'timestamp' => (int)($file['putTime'] / 10000000) //Epoch 时间戳
        ];
    }

    /**
     * 获取公有资源地址
     * @param $path
     * @return string
     */
    public function getUrl($path)
    {
        return \Qiniu_RS_MakeBaseUrl($this->domain, $path);
    }

    /**
     * 获取私有资源地址(公有资源一样可用)
     * @param $path
     * @return string
     */
    public function getPrivateUrl($path)
    {
        $getPolicy = new \Qiniu_RS_GetPolicy();
        return $getPolicy->MakeRequest($this->getUrl($path), null);
    }

    protected function getClient()
    {
        if ($this->_client === null) {
            $this->setClient(new \Qiniu_MacHttpClient(null));
        }
        return $this->_client;
    }

    protected function setClient($client)
    {
        return $this->_client = $client;
    }

    protected function listDirContents($directory, $start = '')
    {
        list($item, $marker, $err) = Qiniu_RSF_ListPrefix($this->getClient(), $this->bucket, $directory, $start);
        if ($err !== 'EOF') {
            $start = $marker;
            $item = array_merge($item, $this->listDirContents($directory, $start));
        }
        return $item;
    }
}