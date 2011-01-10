<?php

	class Run{
		
		public function __construct() {
			$th = new TorrentFetcher("thepiratebay");
			
			$results = $th->lookup("futurama", 2);
			
			$fh = new FeedHandler;
			
			$fh->addItems($results['sd']);
			
			$fh->writeOutDOM("test.xml");
		}
		
		public static function cron() {
			Core::debugLog("starting cron task");
			// Read out what feeds should be created from xml
			$dom = new DOMDocument;
			$dom->preserveWhiteSpace = false;
			$dom->loadXML(file_get_contents("feeds.xml"));
			// Create TorrentFetcher and FeedHandler
			$th = new TorrentFetcher("thepiratebay");
			$fh = new FeedHandler;
			
			// Assume it is regenerate time, so just run feed when called
			// Note: we do everything in one loop. Saves time and memory
			//  Sidenote: gotta love how PHP uses Iterators in foreach
			foreach($dom->getElementsByTagName("feed") as $feed) {
				Core::debugLog("starting feed ". $feed->attributes->getNamedItem("name")->value);
				foreach($feed->childNodes as $setting) {
					switch($setting->nodeName) {
						case "searchString": $settings["searchString"] = $setting->nodeValue; break;
						case "feedPath": $settings["feedPath"] = $setting->nodeValue; break;
					}
				}
				
				// Lookup, just do one page for now, I don't have all day
				Core::debugLog("starting lookup");
				$results = $th->lookup($settings["searchString"], 1);
				
				// Write feed
				$path = Configuration::FEEDS_DIR . $settings["feedPath"];
				Core::debugLog("writing feed to ". $path . "sd.xml");
				$fh->addItems($results['sd']);
				$fh->writeOutDOM($path . "sd.xml");
				
				Core::debugLog("writing feed to ". $path . "hd.xml");
				$fh->addItems($results['hd']);
				$fh->writeOutDOM($path . "hd.xml");
			}
		}
		
	}

?>