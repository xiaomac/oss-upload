<?php

// CLASS
/**
 * OSS协议封装类
 * @author Link (xiaomac.com)
 * @since 2019-10-22
 */

 require_once('OSS.php');

final class OSSWrapper extends OU_ALIOSS {
	private $position = 0, $mode = '', $buffer;
	public function url_stat($path, $flags) {
		$return = false;
		if(stripos(basename($path), '.') === false){//dir
			$options = array();
			$options['osdir'] = 1;//borrow the true via opendir
			$info = self::dir_opendir($path, $options);
			if($info) $return = array('mode' => 16895);
		}else{//file
			self::__getURL($path);
			$info = self::get_object_meta($this->url['host'], $this->url['path']);
			if($info->isOK()){//exist
				$size = isset($info->header['_info']['download_content_length']) ? $info->header['_info']['download_content_length'] : 0;
				if(empty($size)) $size = isset($info->header['content-length']) ? $info->header['content-length'] : 0;
				$return = array(
					'mode' => 33279, 
					'size' => $size, 
					'atime' => $info->header['_info']['filetime'], 
					'mtime' => $info->header['_info']['filetime'], 
					'ctime' => $info->header['_info']['filetime']
				);
			}
		}
		return $return;
	}
	public function unlink($path) {
		self::__getURL($path);
		$info = self::delete_object($this->url['host'], $this->url['path']);
		clearstatcache(true);
		return $info->isOK();
	}
	public function mkdir($path, $mode, $options) {
		self::__getURL(rtrim($path,'/'));
		$info = self::create_object_dir($this->url['host'], $this->url['path']);
		return $info->isOK();
	}
	public function rmdir($path) {
		self::__getURL($path);
		$info = self::delete_object($this->url['host'], $this->url['path']);
		clearstatcache(true);
		return $info->isOK();
	}
	public function rename($path, $to) {
		self::__getURL($path);
        $tourl = parse_url($to);
        $tourl['path'] = isset($tourl['path']) ? substr($tourl['path'], 1) : '';
		$info = self::copy_object($this->url['host'], $this->url['path'], $this->url['host'], $tourl['path']);
		if($info->isOK()) $info = self::delete_object($this->url['host'], $this->url['path']);
		clearstatcache(true);
		return $info->isOK();
	}
	public function dir_opendir($path, $options) {
		self::__getURL($path);
		if(empty($options)) $options = array();
		$options['prefix'] = rtrim($this->url['path'], '/').'/';
		//$options['delimiter'] = '/';
		$info = self::list_object($this->url['host'], $options);
		if($info->isOK()){
			$xml = simplexml_load_string($info->body, 'SimpleXMLElement', LIBXML_NOCDATA);
	        $arr = json_decode(json_encode($xml), true);
			$this->buffer = array();
			if(!empty($arr['CommonPrefixes'])){
				if(isset($arr['CommonPrefixes']['Prefix'])){
					if($key = substr($arr['CommonPrefixes']['Prefix'], strlen($options['prefix']))) $this->buffer[] = $key;
				}else{
					foreach ($arr['CommonPrefixes'] as $k=>$v) {
						if(isset($v['Prefix']) && ($key = substr($v['Prefix'], strlen($options['prefix'])))) $this->buffer[] = $key;
					}
				}
			}
			if(!empty($arr['Contents'])){
				if(isset($arr['Contents']['Key'])){
					if($key = substr($arr['Contents']['Key'], strlen($options['prefix']))) $this->buffer[] = $key;
				}else{
					foreach ($arr['Contents'] as $k=>$v) {
						if(isset($v['Key']) && ($key = substr($v['Key'], strlen($options['prefix'])))) $this->buffer[] = $key;
					}
				}
			}
			if(!empty($options['osdir'])){
				if(!empty($this->buffer)){
					$this->position = 0;
					unset($this->buffer);
					return true;
				}else{
					return false;
				}
			}
			return true;
		}
		return false;
	}
	public function dir_readdir() {
		return (isset($this->buffer[$this->position])) ? $this->buffer[$this->position++] : false;
	}
	public function dir_rewinddir() {
		$this->position = 0;
	}
	public function dir_closedir() {
		$this->position = 0;
		unset($this->buffer);
	}
	public function stream_stat() {
		if (is_object($this->buffer) && isset($this->buffer->headers)){
			return array(
				'size' => $this->buffer->headers['size'],
				'mtime' => $this->buffer->headers['time'],
				'ctime' => $this->buffer->headers['time']
			);
		}else{
			$info = self::get_object_meta($this->url['host'], $this->url['path']);
			$size = isset($info->header['_info']['download_content_length']) ? $info->header['_info']['download_content_length'] : 0;
			if(empty($size)) $size = isset($info->header['content-length']) ? $info->header['content-length'] : 0;
			if($info->isOK()) return array(
				'size' => $size,
				'atime' => $info->header['_info']['filetime'],
				'mtime' => $info->header['_info']['filetime'],
				'ctime' => $info->header['_info']['filetime']
			);
		}
		return false;
	}
	public function stream_open($path, $mode, $options, &$opened_path) {
		if (!in_array($mode, array('r', 'rb', 'w', 'wb'))) return false; // Mode not supported
		$this->mode = substr($mode, 0, 1);
		self::__getURL($path);
		$this->position = 0;
		if ($this->mode == 'r') {
			if (($this->buffer = self::get_object($this->url['host'], $this->url['path'])) !== false) {
				if (is_object($this->buffer->body)) $this->buffer->body = (string)$this->buffer->body;
			} else return false;
		}
		return true;
	}
	public function stream_read($count) {
		if ($this->mode !== 'r' && $this->buffer !== false) return false;
		$data = substr(is_object($this->buffer) ? $this->buffer->body : $this->buffer, $this->position, $count);
		$this->position += strlen($data);
		return $data;
	}
	public function stream_write($data) {
		if ($this->mode !== 'w') return 0;
		$left = substr($this->buffer, 0, $this->position);
		$right = substr($this->buffer, $this->position + strlen($data));
		$this->buffer = $left . $data . $right;
		$this->position += strlen($data);
		return strlen($data);
	}
	public function stream_close() {
		if ($this->mode == 'w') {
			$options = array(
			    'content' => $this->buffer,
			    'length' => strlen($this->buffer)
			);
			self::upload_file_by_content($this->url['host'], $this->url['path'], $options);
		}
		$this->position = 0;
		unset($this->buffer);
	}
	public function stream_seek($offset, $whence) {
		switch ($whence) {
			case SEEK_SET:
                if ($offset < strlen($this->buffer->body) && $offset >= 0) {
                    $this->position = $offset;
                    return true;
                } else return false;
            break;
            case SEEK_CUR:
                if ($offset >= 0) {
                    $this->position += $offset;
                    return true;
                } else return false;
            break;
            case SEEK_END:
                $bytes = strlen($this->buffer->body);
                if ($bytes + $offset >= 0) {
                    $this->position = $bytes + $offset;
                    return true;
                } else return false;
            break;
            default: return false;
        }
    }
	public function stream_tell() {
		return $this->position;
	}
	public function stream_eof() {
		return $this->position >= strlen(is_object($this->buffer) ? $this->buffer->body : $this->buffer);
	}
	public function stream_flush() {
		$this->position = 0;
		return true;
	}
    public function stream_metadata($path, $options, $var) {
    	//http://php.net/manual/en/streamwrapper.stream-metadata.php
        return true;
    }
	public function stream_truncate($new_size) {
		//$data = substr(is_object($this->buffer) ? $this->buffer->body : $this->buffer, 0, $new_size);
		//$this->buffer = $data;
		//$this->position = strlen($data);
		return true;
	}
    private function __getURL($path) {
        $this->url = parse_url($path);
        if(!isset($this->url['scheme']) || $this->url['scheme'] !== 'oss') return $this->url;
        if(defined('OSS_ACCESS_ID')&&defined('OSS_ACCESS_KEY')) self::setAuth(OSS_ACCESS_ID,OSS_ACCESS_KEY);
        $this->url['path'] = isset($this->url['path']) ? substr($this->url['path'], 1) : '';
    }
}
stream_wrapper_register('oss', 'OSSWrapper');
