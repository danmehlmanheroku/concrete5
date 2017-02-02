<?php
namespace Concrete\Core\Foundation;

use Config;

/**
 * Useful functions for getting paths for concrete5 items.
 *
 * \@package Core
 *
 * @author Andrew Embler <andrew@concrete5.org>
 * @copyright  Copyright (c) 2003-2012 Concrete5. (http://www.concrete5.org)
 * @license    http://www.concrete5.org/license/     MIT License
 */
class Environment
{
    /**
     * @var string[]
     */
    protected $coreOverrides = [];

    /**
     * @var string[]
     */
    protected $corePackages = [];

    /**
     * @var array
     */
    protected $coreOverridesByPackage = [];

    /**
     * @var bool
     */
    protected $overridesScanned = false;

    /**
     * @var array
     */
    protected $cachedOverrides = [];

    /**
     * @var bool
     */
    protected $autoLoaded = false;

    /**
     * @return self
     */
    public static function get()
    {
        static $env;
        if (!isset($env)) {
            if (file_exists(Config::get('concrete.cache.directory') . '/' . Config::get('concrete.cache.environment.file'))) {
                $r = @file_get_contents(Config::get('concrete.cache.directory') . '/' . Config::get('concrete.cache.environment.file'));
                if ($r) {
                    $en = @unserialize($r);
                    if ($en instanceof self) {
                        $env = $en;
                        $env->autoLoaded = true;

                        return $env;
                    }
                }
            }
            $env = new self();
        }

        return $env;
    }

    public static function saveCachedEnvironmentObject()
    {
        if (!file_exists(Config::get('concrete.cache.directory') . '/' . Config::get('concrete.cache.environment.file'))) {
            $env = new self();
            $env->getOverrides();
            @file_put_contents(Config::get('concrete.cache.directory') . '/' . Config::get('concrete.cache.environment.file'), serialize($env));
        }
    }

    public function clearOverrideCache()
    {
        $cacheFile = Config::get('concrete.cache.directory') . '/' . Config::get('concrete.cache.environment.file');
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
        $this->overridesScanned = false;
        $this->cachedOverrides = [];
    }

    /**
     * @var string
     */
    protected $ignoreFiles = ['__MACOSX'];

    public function reset()
    {
        $this->ignoreFiles = ['__MACOSX'];
    }

    /**
     * Builds a list of all overrides.
     */
    protected function getOverrides()
    {
        $check = [
            DIR_FILES_BLOCK_TYPES,
            DIR_FILES_CONTROLLERS,
            DIR_FILES_ELEMENTS,
            DIR_APPLICATION . '/' . DIRNAME_ATTRIBUTES,
            DIR_APPLICATION . '/' . DIRNAME_AUTHENTICATION,
            DIR_FILES_JOBS,
            DIR_APPLICATION . '/' . DIRNAME_CSS,
            DIR_APPLICATION . '/' . DIRNAME_JAVASCRIPT,
            DIR_FILES_EMAIL_TEMPLATES,
            DIR_FILES_CONTENT,
            DIR_FILES_THEMES,
            DIR_FILES_TOOLS,
            DIR_APPLICATION . '/' . DIRNAME_PAGE_TEMPLATES,
            DIR_APPLICATION . '/' . DIRNAME_VIEWS,
            DIR_APPLICATION . '/' . DIRNAME_CLASSES,
            DIR_APPLICATION . '/' . DIRNAME_MENU_ITEMS,
        ];
        foreach ($check as $loc) {
            if (is_dir($loc)) {
                $contents = $this->getDirectoryContents($loc, [], true);
                foreach ($contents as $f) {
                    $item = str_replace(DIR_APPLICATION . '/', '', $f);
                    $item = str_replace(DIR_BASE . '/', '', $item);
                    $this->coreOverrides[] = $item;
                }
            }
        }

        if (is_dir(DIR_PACKAGES_CORE)) {
            $this->corePackages = $this->getDirectoryContents(DIR_PACKAGES_CORE);
        }

        $this->overridesScanned = true;
    }

    /**
     * @param string $dir
     * @param string[] $ignoreFilesArray
     * @param bool $recursive
     *
     * @return string[]
     */
    public function getDirectoryContents($dir, $ignoreFilesArray = [], $recursive = false)
    {
        $ignoreFiles = array_merge($this->ignoreFiles, $ignoreFilesArray);
        $aDir = [];
        if (is_dir($dir)) {
            $handle = opendir($dir);
            while (($file = readdir($handle)) !== false) {
                if (substr($file, 0, 1) != '.' && (!in_array($file, $ignoreFiles))) {
                    if (is_dir($dir . '/' . $file)) {
                        if ($recursive) {
                            $aDir = array_merge($aDir, $this->getDirectoryContents($dir . '/' . $file, $ignoreFiles, $recursive));
                            $file = $dir . '/' . $file;
                        }
                        $aDir[] = preg_replace("/\/\//si", '/', $file);
                    } else {
                        if ($recursive) {
                            $file = $dir . '/' . $file;
                        }
                        $aDir[] = preg_replace("/\/\//si", '/', $file);
                    }
                }
            }
            closedir($handle);
        }

        return $aDir;
    }

    /**
     * @param string $segment
     * @param \Concrete\Core\Package\Package|\Concrete\Core\Entity\Package|string $pkgOrHandle
     */
    public function overrideCoreByPackage($segment, $pkgOrHandle)
    {
        $pkgHandle = is_object($pkgOrHandle) ? $pkgOrHandle->getPackageHandle() : $pkgOrHandle;
        $this->coreOverridesByPackage[$segment] = $pkgHandle;
    }

    /**
     * @param string $segment
     * @param \Concrete\Core\Package\Package|\Concrete\Core\Entity\Package|string $pkgHandle
     *
     * @return EnvironmentRecord
     */
    public function getRecord($segment, $pkgHandle = false)
    {
        if (is_object($pkgHandle)) {
            $pkgHandle = $pkgHandle->getPackageHandle();
        } else {
            $pkgHandle = (string) $pkgHandle;
        }

        if (!$this->overridesScanned) {
            $this->getOverrides();
        }

        if (isset($this->cachedOverrides[$segment][$pkgHandle])) {
            return $this->cachedOverrides[$segment][$pkgHandle];
        }

        $obj = new EnvironmentRecord();
        $obj->pkgHandle = null;

        if (!in_array($segment, $this->coreOverrides) && !$pkgHandle && !array_key_exists($segment, $this->coreOverridesByPackage)) {
            $obj->file = DIR_BASE_CORE . '/' . $segment;
            $obj->url = ASSETS_URL . '/' . $segment;
            $obj->override = false;
            $this->cachedOverrides[$segment][''] = $obj;

            return $obj;
        }

        if (in_array($segment, $this->coreOverrides)) {
            $obj->file = DIR_APPLICATION . '/' . $segment;
            $obj->url = REL_DIR_APPLICATION . '/' . $segment;
            $obj->override = true;
            $this->cachedOverrides[$segment][''] = $obj;

            return $obj;
        }

        if (array_key_exists($segment, $this->coreOverridesByPackage)) {
            $pkgHandle = $this->coreOverridesByPackage[$segment];
            $obj->pkgHandle = $pkgHandle;
        }

        if (!in_array($pkgHandle, $this->corePackages)) {
            $dirp = DIR_PACKAGES . '/' . $pkgHandle;
            $obj->url = DIR_REL . '/' . DIRNAME_PACKAGES . '/' . $pkgHandle . '/' . $segment;
        } else {
            $dirp = DIR_PACKAGES_CORE . '/' . $pkgHandle;
            $obj->url = ASSETS_URL . '/' . DIRNAME_PACKAGES . '/' . $pkgHandle . '/' . $segment;
        }
        $obj->file = $dirp . '/' . $segment;
        $obj->override = false;
        $this->cachedOverrides[$segment][$pkgHandle] = $obj;

        return $obj;
    }

    /**
     * Bypasses overrides cache to get record.
     *
     * @param string $segment
     * @param \Concrete\Core\Package\Package|\Concrete\Core\Entity\Package|string $pkgHandle
     *
     * @return EnvironmentRecord
     */
    public function getUncachedRecord($segment, $pkgHandle = false)
    {
        $obj = new EnvironmentRecord();
        if (is_object($pkgHandle)) {
            $pkgHandle = $pkgHandle->getPackageHandle();
        }
        $obj->override = false;
        if (file_exists(DIR_APPLICATION . '/' . $segment)) {
            $obj->file = DIR_APPLICATION . '/' . $segment;
            $obj->override = true;
            $obj->url = REL_DIR_APPLICATION . '/' . $segment;
        } elseif ($pkgHandle) {
            $dirp1 = DIR_PACKAGES . '/' . $pkgHandle . '/' . $segment;
            $dirp2 = DIR_PACKAGES_CORE . '/' . $pkgHandle . '/' . $segment;
            if (file_exists($dirp2)) {
                $obj->file = $dirp2;
                $obj->url = ASSETS_URL . '/' . DIRNAME_PACKAGES . '/' . $pkgHandle . '/' . $segment;
            } elseif (file_exists($dirp1)) {
                $obj->file = $dirp1;
                $obj->url = DIR_REL . '/' . DIRNAME_PACKAGES . '/' . $pkgHandle . '/' . $segment;
            }
        } else {
            $obj->file = DIR_BASE_CORE . '/' . $segment;
            $obj->url = ASSETS_URL . '/' . $segment;
        }

        return $obj;
    }

    /**
     * Returns a full path to the subpath segment. Returns false if not found.
     *
     * @param string $subpath
     * @param \Concrete\Core\Package\Package|\Concrete\Core\Entity\Package|string $pkgIdentifier
     *
     * @return string
     */
    public function getPath($subpath, $pkgIdentifier = false)
    {
        $r = $this->getRecord($subpath, $pkgIdentifier);

        return $r->file;
    }

    /**
     * Returns  a public URL to the subpath item. Returns false if not found.
     *
     * @param string $subpath
     * @param \Concrete\Core\Package\Package|\Concrete\Core\Entity\Package|string $pkgIdentifier
     *
     * @return string
     */
    public function getURL($subpath, $pkgIdentifier = false)
    {
        $r = $this->getRecord($subpath, $pkgIdentifier);

        return $r->url;
    }

    /**
     * @return string[]
     */
    public function getOverrideList()
    {
        $this->getOverrides();

        return $this->coreOverrides;
    }
}