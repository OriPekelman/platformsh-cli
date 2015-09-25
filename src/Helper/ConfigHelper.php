<?php
namespace Platformsh\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Platformsh\Cli\Helper\GitHelper;

class ConfigHelper extends Helper
{

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'config';
    }

    public function __construct($application)
    {
        $this->gitHelper = new GitHelper();
    }

    /**
     * How many platform.yaml files does this environment have?
     * @return integer
     */
    public function hasPlatformYaml()
    {              
        return $this->gitHelper->matchFile(".platform.app.yaml");
    }

    /**
     * How many services.yaml files does this environment have?
     * @return integer
     */
    public function hasServicesYaml()
    {
        return $this->gitHelper->matchFile(".platform/services.yaml",null, true);
    }
    
    /**
     * How many routes.yaml files does this environment have?
     * @return integer
     */
    public function hasRoutesYaml()
    {
        return $this->gitHelper->matchFile(".platform/routes.yaml", null, true);
    }

    /**
     * create platform.yaml from template
     * @return boolean
     */
    public function createPlatformYaml($dir="", $name="app",$type="php:5.6", $flavor="composer", $root='/')
    {
      $file = 
      '# This is just an example of a minimal configuration, you should '."\n".
      '# follow the docs to create a configuraiton suitable for your application'."\n".
      '# https://docs.platform.sh'.PHP_EOL.
      'name: "'.$name.'"'.PHP_EOL.
      'type: '.$type.PHP_EOL.
      'build:'.PHP_EOL.
      '    flavor: '.$flavor.PHP_EOL.
      'web:'.PHP_EOL.
      '  document_root: "'.$root.'"'.PHP_EOL.
      '  passthru: "/index.php"'.PHP_EOL.
      'relationships:'.PHP_EOL.
      '    database: "mysql:mysql"'.PHP_EOL.
      '"disk: 2048"';
EOD;
      $dir= $dir ? getcwd(): $dir;
      file_put_contents($dir.".platform.app.yaml", $file);
    }

    /**
     * create services.yaml from template
     * @return boolean
     */
    public function createServicesYaml($dir="")
    {
      if (!is_dir('.platform')) {mkdir('.platform');}
$file = <<<EOD
mysql:
    type: mysql
    disk: 1048
EOD;
      $dir= $dir ? getcwd(): $dir;
      file_put_contents($dir.".platform/services.yaml", $file);
    }
    
    /**
     * create routes.yaml from template
     * @return integer
     */
    public function createRoutesYaml($dir="")
    {
      
      if (!is_dir('.platform')) {mkdir('.platform');}
$file = <<<EOD
"http://{default}/":
    type: upstream
    upstream: "app:php"
EOD;
      $dir= $dir ? getcwd(): $dir;
      file_put_contents($dir.".platform/routes.yaml", $file);
    }
}
