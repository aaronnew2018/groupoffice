<?php

namespace go\modules\community\dev\controller;

use go\core\Controller;
use go\core\Environment;
use go\core\fs\Blob;
use go\core\fs\File;
use go\core\jmap\Response;
use go\core\Language as LangModel;

use function GO;

class Language extends Controller {
	
	private $handle;
	private $en;
	private $nl;

	public function export($params) {
		GO()->getLanguage()->setLanguage("nl");

//for checking arrays() in english translation
		$this->en = new LangModel();
		$this->en->setLanguage("en");

//to check if intermesh has defined it as a core translation
		$this->nl = new LangModel();
		$this->nl->setLanguage("nl");

		$rootFolder = Environment::get()->getInstallFolder();

		$coreFiles = $rootFolder->getFolder("views/Extjs3/javascript")->find('/.*\.js/', false);
		
		$csvFile = File::tempFile('csv');

		$this->handle = $csvFile->open('w+');
		
		fputcsv($this->handle, [
				"package",
				"module",
				"EN",
				GO()->getLanguage()->getIsoCode(),
				"source"
		]);

		$core = [];
		foreach ($coreFiles as $file) {
			$core = array_merge($core, $this->getStringsFromJS($file));
		}

		$core = array_unique($core);

		$packageFolders = $rootFolder->getFolder("go/modules")->getFolders();
		$packageFolders[] = $rootFolder->getFolder("modules");

		foreach ($packageFolders as $packageFolder) {
			foreach ($packageFolder->getFolders() as $moduleFolder) {
				$files = $moduleFolder->find("/.*\.js/");
				$strings = [];
				foreach ($files as $file) {
					$strings = array_merge($strings, $this->getStringsFromJS($file));
				}

				$strings = array_unique($strings);
				$package = $packageFolder->getName();
				if ($package == "modules") {
					$package = "legacy";
				}
				$modStrings = [];
				foreach ($strings as $string) {
					if ($this->nl->translationExists($string)) {

						//save core strings for later.
						if (!in_array($string, $core)) {
							$core[] = $string;
						}
					} else {
						$modStrings[] = $string;
					}
				}

				$this->writeStrings($package, $moduleFolder->getName(), $modStrings, $file->getRelativePath($rootFolder));
			}
		}

		$this->writeStrings("core", "core", $core, "*");
		
		//todo, this is refsactored in master
		$blob = Blob::fromTmp($csvFile->getPath());
		$blob->type = "text/csv";
		$blob->name = "lang.csv";
		$blob->modified = time();
		$blob->save();
		
		Response::get()->addResponse(["blobId" => $blob->id]);
	}

	private function getStringsFromJS(File $file) {
		$content = $file->getContents();

		preg_match_all('/[\s\+\(\[:=]t\s*\(\s*[\'"](.+?)[\'"]\s*[\),]/', $content, $matches);
//		if(!empty($matches[1]))
//var_dump($matches);
		return array_map('stripslashes', $matches[1]);
	}

	function writeStrings($package, $module, $strings, $relPath) {		

		foreach ($strings as $string) {
			$enTranslation = $this->en->t($string, $package, $module);

			$translated = GO()->t($string, $package, $module);
			if (is_array($enTranslation)) {
				foreach ($enTranslation as $key => $stringItem) {
					$translatedItem = $translated[$key] ?? "";

					$fields = [
							$package,
							$module,
							$string . '[' . $key . ']',
							$translatedItem == $stringItem ? "" : $translatedItem,
							$relPath
					];
					fputcsv($this->handle, $fields);
				}
			} else {
				$fields = [
						$package,
						$module,
						$string,
						$translated == $string ? "" : $translated,
						$relPath
				];

				fputcsv($this->handle, $fields);
			}
		}
	}

}