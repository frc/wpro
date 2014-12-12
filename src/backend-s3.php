<?php

if (!defined('ABSPATH')) exit();

class WPRO_Backend_S3 {

	const NAME = 'Amazon S3';

	function activate() {
		wpro()->options->register('wpro-aws-key');
		wpro()->options->register('wpro-aws-secret');
		wpro()->options->register('wpro-aws-bucket');
		wpro()->options->register('wpro-aws-cloudfront');
		wpro()->options->register('wpro-aws-virthost');
		wpro()->options->register('wpro-aws-endpoint');
		wpro()->options->register('wpro-aws-ssl');

		add_filter('wpro_backend_handle_upload', array($this, 'handle_upload'));
		add_filter('wpro_backend_retrieval_baseurl', array($this, 'url'));
	}

	function admin_form() {
		?>
			<h3><?php echo(self::NAME); ?></h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">AWS Key</th>
					<td>
						<input type="text" name="wpro-aws-key" />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">AWS Secret</th>
					<td>
						<input type="text" name="wpro-aws-secret" />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">S3 Bucket</th>
					<td>
						<input type="text" name="wpro-aws-bucket" />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Virtual hosting enabled for the S3 Bucket</th>
					<td>
						<input name="wpro-aws-virthost" id="wpro-aws-virthost" value="1" type="checkbox" />
					</td>
				</tr>
			</table>
		<?php
	}

	function handle_upload($data) {
		$file = $data['file'];
		$url = $data['url'];
		$mime = $data['type'];

		wpro()->debug->log('WPROS3::upload("' . $file . '", "' . $url . '", "' . $mime . '");');
		$url = $this->wpro()->url->normalize($url);
		if (!preg_match('/^http(s)?:\/\/([^\/]+)\/(.*)$/', $url, $regs)) return false;
		$url = $regs[3];

		if (!file_exists($file)) return false;
		$this->removeTemporaryLocalData($file);

		$fin = fopen($file, 'r');
		if (!$fin) return false;

		$fout = fsockopen($this->endpoint, 80, $errno, $errstr, 30);
		if (!$fout) return false;
		$datetime = gmdate('r');
		$string2sign = "PUT\n\n" . $mime . "\n" . $datetime . "\nx-amz-acl:public-read\n/" . $url;

		wpro()->debug->log('STRING TO SIGN:');
		wpro()->debug->log($string2sign);
		$debug = '';
		for ($i = 0; $i < strlen($string2sign); $i++) $debug .= dechex(ord(substr($string2sign, $i, 1))) . ' ';
		wpro()->debug->log($debug);

		// Todo: Make this work with php cURL instead of fsockopen/etc..

		$query = "PUT /" . $url . " HTTP/1.1\n";
		$query .= "Host: " . $this->endpoint . "\n";
		$query .= "x-amz-acl: public-read\n";
		$query .= "Connection: keep-alive\n";
		$query .= "Content-Type: " . $mime . "\n";
		$query .= "Content-Length: " . filesize($file) . "\n";
		$query .= "Date: " . $datetime . "\n";
		$query .= "Authorization: AWS " . $this->key . ":" . $this->amazon_hmac($string2sign) . "\n\n";

		wpro()->debug->log('SEND:');
		wpro()->debug->log($query);

		fwrite($fout, $query);
		while (feof($fin) === false) fwrite($fout, fread($fin, 8192));
		fclose($fin);

		// Get the amazon response:
		wpro()->debug->log('RECEIVE:');
		$response = '';
		while (!feof($fout)) {
			$data = fgets($fout, 256);
			wpro()->debug->log($data);
			$response .= $data;
			if (strpos($response, "\r\n\r\n") !== false) { // Header fully returned.
				wpro()->debug->log('ALL RESPONSE HEADERS RECEIVED.');
				if (strpos($response, 'Content-Length: 0') !== false) break; // Return if Content-Length: 0 (and header is fully returned)
				if (substr($response, -7) == "\r\n0\r\n\r\n") break; // Keep-alive responses does not return EOF, they end with this string.
			}
		}

		fclose($fout);

		if (strpos($response, '<Error>') !== false) return false;

		return true;
	}

	function amazon_hmac($string) {
		return base64_encode(extension_loaded('hash') ?
		hash_hmac('sha1', $string, $this->secret, true) : pack('H*', sha1(
		(str_pad($this->secret, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
		pack('H*', sha1((str_pad($this->secret, 64, chr(0x00)) ^
		(str_repeat(chr(0x36), 64))) . $string)))));
	}


	function deactivate() {
		wpro()->options->deregister('wpro-aws-key');
		wpro()->options->deregister('wpro-aws-secret');
		wpro()->options->deregister('wpro-aws-bucket');
		wpro()->options->deregister('wpro-aws-cloudfront');
		wpro()->options->deregister('wpro-aws-virthost');
		wpro()->options->deregister('wpro-aws-endpoint');
		wpro()->options->deregister('wpro-aws-ssl');

		remove_filter('wpro_backend_handle_upload', array($this, 'handle_upload'));
		remove_filter('wpro_backend_retrieval_baseurl', array($this, 'url'));
	}

	function url($value) {
		$protocol = 'http';
		if (wpro()->options->get('wpro-aws-ssl')) {
			$protocol = 'https';
		}

		# this needs some more testing, but it seems like we have to use the
		# virtual-hosted-style for US Standard region, and the path-style
		# for region-specific endpoints:
		# (however we used the virtual-hosted style for everything before,
		# and that did work, so something has changed at amazons end.
		# is there any difference between old and new buckets?)
		if (wpro()->options->get('wpro-aws-endpoint') == 's3.amazonaws.com') {
			$url = $protocol . '://' . trim(str_replace('//', '/', wpro()->options->get('wpro-aws-bucket') . '.s3.amazonaws.com/' . trim(wpro()->options->get('wpro-folder'))), '/');
		} else {
			$url = $protocol . '://' . trim(str_replace('//', '/', wpro()->options->get('wpro-aws-endpoint') . '/' . wpro()->options->get('wpro-aws-bucket') . '/' . trim(wpro()->options->get('wpro-folder'))), '/');
		}

		return $url;
	}
		

}

function wpro_setup_s3_backend() {
	wpro()->backends->register('WPRO_Backend_S3'); // Name of the class.
}
add_action('wpro_setup_backend', 'wpro_setup_s3_backend');
