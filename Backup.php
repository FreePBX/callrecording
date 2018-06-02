<?php
namespace FreePBX\modules\Callrecording;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
  public function runBackup($id,$transaction){
    $configs = [];
    $configs['rules'] = $this->FrePBX->Callrecording->listAll();
    $configs['modules'] = $this->FrePBX->Callrecording->dumpExtensions();
    $this->addConfigs($configs);
  }
}