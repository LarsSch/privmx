<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

if (class_exists("\\io\\privfs\\plugin\\updater\\UpdateService")) {
  
  class UpdateService2 extends \io\privfs\plugin\updater\UpdateService {
    
    public function performPostUpdateScript($updateID, $service) {
      $distFiles = array('talk', 'contact', 'validate');
      
      $distPath = $this->getUpdatePath($updateID, "privmx");
      $destPath = $this->getRootDestPath();
      foreach ($distFiles as $file) {
        $src = self::joinPaths($distPath, $file);
        $dest = self::joinPaths($destPath, $file);
        $this->writeLogMessage($updateID, "Copy from $src to $dest");
        if (!$this->fsCopy($src, $dest)) {
          $this->writeLogMessage($updateID, "Can't copy {$src} to {$dest} ");
          $error = $this->getError(\io\privfs\plugin\updater\UpdateError::ERROR_COPY_FILES, array("source" => $src, "destination" => $dest));
          $this->changeStepStatus($updateID, "copy-files", $this->STATUS_FAILED, $error);
          return $error;
        }
      }
    }
  }
  
  function postUpdateScript($updateID, $service) {
    global $config;
    $service2 = new UpdateService2($config);
    return $service2->performPostUpdateScript($updateID, $service);
  }
}