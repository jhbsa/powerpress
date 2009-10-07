<?php
	// mp3info class for use in the Blubrry PowerPress
	// Main purpose of this file is to obtain the duration string for the itunes:duration field.
	// Library is packaged thin with only basic mp3 support.
	// Concept with this library is to get the information without downlaoding the entire file.
	// for efficienccy
	
	class Mp3Info {
		//var $m_DownloadBytesLimit = 1638400; // 1600K (1600*1024) bytes file
		//var $m_DownloadBytesLimit = 204800; // 200K (200*1024) bytes file
		var $m_DownloadBytesLimit = 327680; // 320K (320*1024) bytes file
		//var $m_DownloadBytesLimit = 409600; // 400K (400*1024) bytes file
		var $m_RedirectLimit = 12; // Number of times to do the 302 redirect
		var $m_UserAgent = 'Blubrry PowerPress';
		var $m_error = '';
		var $m_warnings = array();
		var $m_ContentLength = false;
		var $m_RedirectCount = 0;
		Var $m_file_size_only = false;
		
		var $m_data = '';

		// Constructor
		function Mp3Info()
		{
			// Empty for now
			$this->m_UserAgent = 'Blubrry PowerPress/'.POWERPRESS_VERSION;
		}
		
		/*
		Set how much of the media file to download. Default: 25K
		*/
		function SetDownloadBytesLimit($limit=204800)
		{
			$this->m_DownloadBytesLimit = $limit;
		}
		
		/*
		Set how many times we can follow a HTTP 30x header redirect before we fail.
		*/
		function SetRedirectLimit($limit=5)
		{
			$this->m_RedirectLimit = $limit;
		}
		
		/*
		Set the user agent to be sent by this plugin
		*/
		function SetUserAgent($user_agent)
		{
			$this->m_UserAgent = $user_agent;
		}
		
		/*
		Return the last set error message
		*/
		function GetError()
		{
			return $this->m_error;
		}
		
		/*
		Set the last error message
		*/
		function SetError($msg)
		{
			$this->m_error = $msg;
		}
		
		function GetWarnings()
		{
			return $this->m_warnings;
		}
		
		function AddWarning($msg)
		{
			$this->m_warnings[] = $msg;
		}
		
		/*
		Get the length in bytes of the file to download.
		*/
		function GetContentLength()
		{
			return $this->m_ContentLength;
		}
		
		/*
		Get the number of times we followed 30x header redirects
		*/
		function GetRedirectCount()
		{
			return $this->m_RedirectCount;
		}

		/*
		Start the download and get the headers, handles the redirect if there are any
		*/
		function Download($url, $RedirectCount = 0)
		{
			if( !ini_get( 'allow_url_fopen' ) && !function_exists( 'curl_init' ) )
			{
				$this->SetError( __('Your server must either have the php.ini setting \'allow_url_fopen\' enabled or have the PHP cURL library installed in order to continue.') );
				return false;
			}
			
			if( function_exists( 'curl_init' ) )
				return $this->DownloadCurl($url);
			
			// The following code relies on fopen_url capability.
			if( $RedirectCount > $this->m_RedirectLimit )
			{
				$this->SetError( 'Media URL exceeded redirect limit of '.$this->m_RedirectLimit .' (fopen).' );
				return false;
			}
			
			$this->m_ContentLength = false;
			$this->m_RedirectCount = $RedirectCount;
			
			$urlParts = parse_url($url);
			if( !isset( $urlParts['host']) )
			{
				if( empty($url) )
					$this->SetError( 'Unable to obtain host name from URL.' );
				else
					$this->SetError( 'Unable to obtain  host name from the URL: '.$url );
				return false;
			}
			if( !isset( $urlParts['path']) )
				$urlParts['path'] = '/';
			if( !isset( $urlParts['port']) )
				$urlParts['port'] = 80;
			if( !isset( $urlParts['scheme']) )
				$urlParts['scheme'] = 'http';
			
			$fp = fsockopen($urlParts['host'], $urlParts['port'], $errno, $errstr, 30);
			if ($fp)
			{
				// Create and send the request headers
				$RequestHeaders = ($this->m_file_size_only?'HEAD ':'GET ').$urlParts['path'].(isset($urlParts['query']) ? '?'.@$urlParts['query'] : '')." HTTP/1.0\r\n";
				$RequestHeaders .= 'Host: '.$urlParts['host'].($urlParts['port'] != 80 ? ':'.$urlParts['port'] : '')."\r\n";
				$RequestHeaders .= "Connection: Close\r\n";
				$RequestHeaders .= "User-Agent: {$this->m_UserAgent}\r\n";
				fwrite($fp, $RequestHeaders."\r\n");
				
				$Redirect = false;
				$RedirectURL = false;
				$ContentLength = false;
				$ContentType = false;
				$ReturnCode = 0;
				$headers = '';
				// Loop through the headers
				while( !feof($fp) )
				{
					$line = fgets($fp, 1280); // Get the next header line...
					if( $line === false )
						break; // Something happened
					if ($line == "\r\n")
						break; // Okay we're ending the headers, now onto the content
					
					$headers .= $line;
					$line = rtrim($line); // Clean out the new line characters

					list($key, $value) = explode(':', $line, 2);
					$key = trim($key);
					$value = trim($value);
					
					if( stristr($line, '301 Moved Permanently') || stristr($line, '302 Found') || stristr($line, '307 Temporary Redirect') )
					{
						$Redirect = true; // We are dealing with a redirect, lets handle it
					}
					else if( preg_match('/^HTTPS?\/\d\.\d (\d{3})(.*)/i', $line, $matches) )
					{
						$ReturnCode = $matches[1];
						if( $ReturnCode < 200 || $ReturnCode > 206 )
						{
							fclose($fp);
							$this->SetError('HTTP '.$ReturnCode.$matches[2]);
							return false;
						}
					}
					else
					{
						switch( strtolower($key) )
						{
							case 'location': {
								$RedirectURL = $value;
							}; break;
							case 'content-length': {
								$ContentLength = $value;
							}; break;
						}
					}
        }
				
				// Loop through the content till we reach our limit...
				$Content = '';
				if( $this->m_DownloadBytesLimit )
				{
					while( !feof($fp) )
					{
						$Content .= fread($fp, 8096);
						if( strlen($Content) > $this->m_DownloadBytesLimit )
							break; // We got enough of the file we should be able to determine the duration
					}
				}
				fclose($fp);
				
				// If we're dealing with a redirect, lets call our nested function call now
				if( $Redirect )
				{
					unset($Content); // clear what may be using a lot of memory
					return $this->Download($RedirectURL, $RedirectCount + 1 ); // Follow this redirect
				}
				else // Otherwise, lets set the data and return true for part two
				{
					if( $this->m_file_size_only )
					{
						if( $ContentLength )
						{
							$this->m_ContentLength = $ContentLength;
							return true;
						}
						else
						{
							$this->SetError('Unable to obtain media size from web server.');
							return false;
						}
					}
						
					//global $TempFile;
					if( function_exists('get_temp_dir') ) // If wordpress function is available, lets use it
						$TempFile = tempnam(get_temp_dir(), 'wp_powerpress');
					else // otherwise use the default path
						$TempFile = tempnam('/tmp', 'wp_powerpress');
					
					if( $TempFile === false )
					{
						$this->SetError('Unable to save media information to temporary directory.');
						return false;
					}
					
					$fp = fopen( $TempFile, 'w' );
					fwrite($fp, $Content);
					fclose($fp);
					
					if( $ContentLength )
						$this->m_ContentLength = $ContentLength;
					return $TempFile;
				}
			}
			$this->SetError('Unable to connect to host '.$urlParts['host'].'.');
			return false;
		}
		
		/*
		Alternative method (curl) for downloading portion of a media file
		*/
		function DownloadCurl($url, $RedirectCount = 0)
		{
			// In case we are dealing with a restriction with a server that does not allow cURL to do redirects itself...
			if ( ini_get('safe_mode') || ini_get('open_basedir') )
			{
				if( $RedirectCount > $this->m_RedirectLimit )
				{
					$this->SetError( 'Media URL exceeded redirect limit of '.$this->m_RedirectLimit .' (cURL in safe mode).' );
					return false;
				}
				$this->m_RedirectCount = $RedirectCount;
			}
			
			$curl = curl_init();
			// First, get the content-length...
			curl_setopt($curl, CURLOPT_USERAGENT, $this->m_UserAgent );
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HEADER, true); // header will be at output
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD'); // HTTP request 
			curl_setopt($curl, CURLOPT_NOBODY, true );
			curl_setopt($curl, CURLOPT_FAILONERROR, true);
			if ( !ini_get('safe_mode') && !ini_get('open_basedir') )
			{
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($curl, CURLOPT_MAXREDIRS, $this->m_RedirectLimit);
			}
			else
			{
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
				curl_setopt($curl, CURLOPT_MAXREDIRS, 0 ); // We will attempt to handle redirects ourself
			}
			$Headers = curl_exec($curl);
			$ContentLength = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
			$HttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$ContentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
			$ErrorMsg = curl_error($curl);
			if ( !ini_get('safe_mode') && !ini_get('open_basedir') )
				$this->m_RedirectCount = curl_getinfo($curl, CURLINFO_REDIRECT_COUNT);
			
			if( $HttpCode < 200 || $HttpCode > 250 )
			{
				switch( $HttpCode )
				{
					case 301:
					case 302:
					case 307: {
						if ( !ini_get('safe_mode') && !ini_get('open_basedir') )
						{
							$this->SetError( 'Media URL exceeded redirect limit of '.$this->m_RedirectLimit .' (cURL).' );
						}
						else
						{
							$redirect_url = false;
							if( preg_match('/^location:(.*)$/im', $Headers, $matches) )
								$redirect_url = trim($matches[1]);
							
							if( $redirect_url )
							{
								curl_close($curl);
								return $this->DownloadCurl($redirect_url, $RedirectCount +1);
							}
							else
							{
								$this->SetError( sprintf(__('Unable to obtain HTTP %d redirect URL.'), $HttpCode) );
							}
						}
					}; break;
					default: {
						$this->SetError( curl_error($curl) );
					}; break;
				}
				curl_close($curl);
				return false;
			}
			
			/*
			if( stristr($ContentType, 'text') )
			{
				$this->SetError( 'Invalid content type returned.' );
				return false;
			}
			*/
			
			$FinalURL = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
			curl_close($curl); // Close the first CURL connection
			
			if( $this->m_file_size_only )
			{
				if( $ContentLength )
				{
					$this->m_ContentLength = $ContentLength;
					return true;
				}
				$this->SetError('Unable to obtain media size from server.');
				return false;
			}
			
			global $TempFile;
			if( function_exists('get_temp_dir') ) // If wordpress function is available, lets use it
				$TempFile = tempnam(get_temp_dir(), 'wp_powerpress');
			else // otherwise use the default path
				$TempFile = tempnam('/tmp', 'wp_powerpress');
			if( $TempFile === false )
			{
				$this->SetError('Unable to create temporary file for checking media information.');
				return false;
			}
			
			$fp = fopen($TempFile, 'w+b');
				// Next get the first chunk of the file...
				
			$curl = curl_init();
				curl_setopt($curl, CURLOPT_URL, $FinalURL);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, false); // Don't set this as it is knwon to cause errors with the function callback.
				curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
				curl_setopt($curl, CURLOPT_USERAGENT, $this->m_UserAgent);
				curl_setopt($curl, CURLOPT_FILE, $fp);
				curl_setopt($curl, CURLOPT_HEADER, false); // header will be at output
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET'); // HTTP request 
				curl_setopt($curl, CURLOPT_NOBODY, false );
				if ( !ini_get('safe_mode') && !ini_get('open_basedir') )
				{
					curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($curl, CURLOPT_MAXREDIRS, $this->m_RedirectLimit);
				}
				else
				{
					curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
					curl_setopt($curl, CURLOPT_MAXREDIRS, 0 ); // We will attempt to handle redirects ourself
				}
				curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
				
				// First lets try a range request
				curl_setopt($curl, CURLOPT_RANGE, '0-'.($this->m_DownloadBytesLimit - 1) );
				// curl_setopt($curl, CURLOPT_HTTPHEADER, array('Range: bytes=0-'.($this->m_DownloadBytesLimit - 1) ));
			$success = curl_exec($curl);
				
			if( !$success && curl_getinfo($curl, CURLINFO_HTTP_CODE) == 406 )
			{
				curl_close($curl);
				$curl = curl_init();
				//curl_setopt($curl, CURLOPT_URL, $url);
				curl_setopt($curl, CURLOPT_URL, $FinalURL);
				curl_setopt($curl, CURLOPT_USERAGENT, $this->m_UserAgent);
				curl_setopt($curl, CURLOPT_HEADER, false); // header will be at output
				curl_setopt($curl, CURLOPT_NOBODY, false );
				curl_setopt($curl, CURLOPT_WRITEFUNCTION, array($this, 'remoteread_curl_writefunc') );
				if ( !ini_get('safe_mode') && !ini_get('open_basedir') )
				{
					curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($curl, CURLOPT_MAXREDIRS, $this->m_RedirectLimit);
				}
				else
				{
					curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
					curl_setopt($curl, CURLOPT_MAXREDIRS, 0 ); // We will attempt to handle redirects ourself
				}
				
				$success = curl_exec($curl);
				if( ($success || curl_errno($curl) == 23) && $this->m_data != '' )
				{
					fwrite($fp , $this->m_data);
					$this->m_data = ''; // Free up this memory by setting the value to a blank string
					$success = true; // Actually this was a success
				}
				else if( $success && $this->m_data == '' )
				{
					$this->SetError('Unable to download media.');
					$success = false;
				}
			}
		
			if( !$success )
			{
				if( curl_errno($curl) )
					$this->SetError('Retrieving file info: '.  curl_error($curl) );
				else if( $this->GetError() == '' )
					$this->SetError('Unable to download media.');
			}
			curl_close($curl);
			fclose($fp);
			
			if( $success )
			{
				if( $ContentLength )
					$this->m_ContentLength = $ContentLength;
				return $TempFile;
			}
			
			@unlink($TempFile);
			return false;
		}
		
		/*
		Get the MP3 information
		*/
		function GetMp3Info($File, $file_size_only = false)
		{
			$this->m_file_size_only = $file_size_only;
			$DeleteFile = false;
			if( strtolower( substr($File, 0, 7) ) == 'http://' )
			{
				$LocalFile = $this->Download($File);
				if( $LocalFile === false )
					return false;
					
				if( $file_size_only )
					return true;
					
				$DeleteFile = true;
			}
			else
			{
				if( $file_size_only )
				{
					$this->m_ContentLength = filesize($File);
					return true;
				}
				$LocalFile = $File;
			}
			
			// Hack so this works in Windows, helper apps are not necessary for what we're doing anyway
			define('GETID3_HELPERAPPSDIR', true);
			require_once(dirname(__FILE__).'/getid3/getid3.php');
			$getID3 = new getID3;
			$FileInfo = $getID3->analyze( $LocalFile, $this->m_ContentLength );
			if( $DeleteFile )
				@unlink($LocalFile);
				
			if( $FileInfo )
			{
				// Remove extra data that is not necessary for us to return...
				//unset($FileInfo['mpeg']);
				unset($FileInfo['audio']);
				if( isset($FileInfo['id3v2']) )
					unset($FileInfo['id3v2']);
				if( isset($FileInfo['id3v1']) )
					unset($FileInfo['id3v1']);
					
				$FileInfo['playtime_seconds'] = round($FileInfo['playtime_seconds']);
				
				if( isset($FileInfo['mpeg']['audio']) && $FileInfo['mpeg']['audio'] )
				{
					$Audio = $FileInfo['mpeg']['audio'];
					if( $Audio['sample_rate'] != 22050 && $Audio['sample_rate'] != 44100 )
					{
						// Add warning here
						$this->AddWarning( sprintf(__('Sample Rate %dKhz may cause playback issues, we recommend 22Khz or 44Khz for maximum player compatibility.'), $Audio['sample_rate']/1000  ) );
					}
					
					if( stristr($Audio['channelmode'], 'stereo' ) === false )
					{
						// Add warning here
						$this->AddWarning( sprintf(__('Channel Mode \'%s\' may cause playback issues, we recommend \'joint stereo\' for maximum player compatibility.'), trim($Audio['channelmode']) ) );
					}
				}
				
				return $FileInfo;
			}
			
			return false;
		}
		
		function remoteread_curl_writefunc($curl, $data)
		{
			$this->m_data .= $data;
			if( strlen($this->m_data) > $this->m_DownloadBytesLimit )
			{
				return 0; // stop the download here...
			}
			return strlen($data);
		}
	};
	
	/*
	// Example usage:
	$Mp3Info = new Mp3Info();
	$file = 'http://www.podcampohio.com/podpress_trac/web/177/0/TS-107667.mp3';
	if( $Data = $Mp3Info->GetMp3Info($file) )
	{
		echo 'Success: ';
		echo print_r( $Data );
		echo PHP_EOL;
		exit;
	}
	else
	{
		echo 'Error: ';
		echo $Mp3Info->GetError();
		echo PHP_EOL;
		exit;
	}
	*/
	
?>