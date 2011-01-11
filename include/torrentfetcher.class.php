<?php

	class TorrentFetcher {
		private $trackers = array("thepiratebay" => "http://www.thepiratebay.org/search/%s/%p/7/0",
									"placeholder" => "http://yaddayadda/");
		private $tracker;
		
		/**
		* TorrentFetcher::__construct
		*  Sets up the TorrentFetcher object. The method checks whether $tracker exists and sets
		*  self::tracker to the tracker name. This variable is then used in other methods.
		*/
		
		public function __construct($tracker) {
			if(!isset($this->trackers[$tracker])) {
				// Default to thepiratebay
				$tracker = "thepiratebay";
				Core::warning('invalid $tracker argument, going to default');
			}
			
			$this->tracker = $tracker;
		}
		
		/**
		* TorrentFetcher::lookup
		*  Goes out to fetch html from tracker, parses it and returns a pretty assoc array with
		*  valid torrents.
		*  It has following structure:
		*		"id" => 		Episode id
		*		"fileSize" => 	Size of torrent
		*		"link" =>		Link to torrent file
		*		"date" =>		Date uploaded
		*		"fileName" =>	Name of the file
		*		"airDate" =>	Original air date of episode
		*		"title" =>		Episode's title
		*/
		
		public function lookup($lookup_string, $epGuidesPath = null, $numPages = 1) {
			if(is_null($epGuidesPath)) {
				Core::warning("didn't get \$epGuidesPath");
			} else {
				$epGuides = new EpGuides($epGuidesPath);
			}
			
			// Firstly replace the search string in placeholder url
			$query_string = str_replace("%s", str_replace(" ", "%20", $lookup_string), $this->trackers[$this->tracker]);
			
			// Fetch multiple pages
			if($numPages != 1) {
				$data = array();
				
				for($i = 0; $i < $numPages; $i++) {
					$temp = $this->fetchData($query_string, $i);
					$data = array_merge($data, $temp);
				}
				
			}else{
				// Note: first page is page 0
				$data = $this->fetchData($query_string, 0);
			}
			
			// Remove all junk and doubles
			$ids = array();
			foreach($data as $torrent) {
				// Get data from EpGuides
				// NOTE: set it as a variable, we need a particular field in the if clause later on and php *doesn't*
				//       allow for simultaniously calling a method and getting an assoc field from array
				$torrentExtendedData = $epGuides->lookup($torrent['id']);
				
				// Torrents are discarted when: no episode id
				// We also don't want anything with swesub, psp, ipod, zune in it
				// If uploaded date is before airDate, discart it as a fake
				// NOTE: strtotime(null) < time() evaluates as true. Always. So even if there was no hit it won't throw the
				//  whole clause off to false
				if(!is_null($torrent["id"]) && !preg_match('$(swesub|psp|ipod|zune|norsub){1}$i', $torrent['fileName']) &&
						strtotime($torrentExtendedData['airDate']) <= time()) {
					// Standard definition: filesize is mostely around 170, 180 megs (note: longer episodes?)
					// Use EpGuides to give torrents a title and airDate using array_merge
					// NOTE: array_merge doesn't play nice with null values, so just set to empty array if is_null
					$torrentExtendedData = (!is_null($torrentExtendedData) ? $torrentExtendedData : array());
					
					if($torrent['fileSize'] > 150 && $torrent['fileSize'] < 200 && !$ids['sd'][$torrent['id']]) {
						$validTorrents['sd'][] = array_merge($torrent, $torrentExtendedData);
						$ids['sd'][$torrent['id']] = true;
						
					// HD episodes most of the time have x264 and 720p in the title
					} elseif(preg_match('$720p$i', $torrent['fileName']) && !$ids['hd'][$torrent['id']]) {
						$validTorrents['hd'][] = array_merge($torrent, $epGuides->lookup($torrent['id']));
						$ids['hd'][$torrent['id']] = true;
					}
				}
			}
			
			// Sort (we could use map() here)
			usort($validTorrents['sd'], array("TorrentFetcher", "compareEpisodes"));
			if(!is_null($validTorrents['hd'])) usort($validTorrents['hd'], array("TorrentFetcher", "compareEpisodes"));
			
			return $validTorrents;
		}
		
		/**
		* TorrentFetcher::fetchData
		*  Wrapper around torrentFetcher::processRawDataDOM to account for multiple tries, alternative pages
		*  and error handling
		*/
		private function fetchData($query_string, $page) {
			$i = 0;
			Core::debugLog("query string is <b>". str_replace("%p", $page, $query_string) ."</b>");
			
			while(is_null($data = $this->processRawDataDOM(@file_get_contents(str_replace("%p", $page, $query_string))))
				&& $i < Configuration::TRACKER_FETCH_RETRY) {
				// Sleep some in between tries, if site fetching failed, by now it might have recovered
				sleep(5);
				$i++;
			}
			
			if($i != 0 && $i != Configuration::TRACKER_FETCH_RETRY) Core::warning("fetching tracker took $i retries");
			if(is_null($data)) Core::fatalError("error fetching tracker (tried $i times)");
			
			return $data;
		}
		
		/**
		* torrentFetcher::processRawDataDOM
		*  Processes $data through DOM parsing. Includes include/plugin/$tracker.plugin.php for
		*  site-specific parsing.
		*/
		private function processRawDataDOM($data) {
			$dom = new DOMDocument;
			$dom->preserveWhiteSpace = false;
			
			if(is_null($data)) Core::fatalError("TorrentFetcher::processRawDataDOM didn't get any \$data!!");
			
			// Supress warnings caused by non-validness + remove all html entities
			@$dom->loadHTML(preg_replace("/&#?[a-z0-9]{2,8};/i", "", str_replace("&nbsp;", " ", $data)));
			
			switch($this->tracker){
				case "thepiratebay":
					// DON'T even THINK about using require_once
					require "include/plugin/thepiratebay.plugin.php";
					
					return $output;
				break;
				
				default: return false;
			}
			
			unset($dom);
		}
		
		/**
		* torrentFetcher::isOnline
		*  Placeholder for checking whether a tracker is online or not
		*/
		public static function isOnline($tracker) {
			
		}
		
		
		
		/**
		* TorrentFetcher::compareEpisodes
		*  Compares episodes, used for usort
		*/
		public static function compareEpisodes($a, $b) {
			$a = str_replace(array("S", "E"), "", strtoupper($a["id"]));
			$b = str_replace(array("S", "E"), "", strtoupper($b["id"]));
			
			// Compare season
			if((int)substr($a, 0, 2) < (int)substr($b, 0, 2)) {
				return 1;
			}elseif((int)substr($a, 0, 2) > (int)substr($b, 0, 2)) {
				return -1;
			}elseif((int)substr($a, 0, 2) == (int)substr($b, 0, 2)){
				// Compare episode
				if((int)substr($a, 2, 2) < (int)substr($b, 2, 2)){
					return 1;
				}elseif((int)substr($a, 2, 2) > (int)substr($b, 2, 2)){
					return -1;
				}else{
					return 0;
				}
			}
		}
		
	}

?>