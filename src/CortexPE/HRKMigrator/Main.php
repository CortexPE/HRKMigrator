<?php

/***
 *        __  ___                           __
 *       / / / (_)__  _________ ___________/ /_  __  __
 *      / /_/ / / _ \/ ___/ __ `/ ___/ ___/ __ \/ / / /
 *     / __  / /  __/ /  / /_/ / /  / /__/ / / / /_/ /
 *    /_/ /_/_/\___/_/   \__,_/_/   \___/_/ /_/\__, /
 *                                            /____/
 *
 * Hierarchy - Migrate PurePerms & PureChat configuration and data to Hierarchy and HRKChat
 * Copyright (C) 2019-Present CortexPE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace CortexPE\HRKMigrator;

use CortexPE\Hierarchy\Hierarchy;
use CortexPE\Hierarchy\role\Role;
use CortexPE\HRKMigrator\data\PPMySQLSource;
use CortexPE\HRKMigrator\data\PPYAMLv1Source;
use CortexPE\HRKMigrator\data\PPYAMLv2Source;
use Phar;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginManager;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use function dirname;
use function is_dir;
use function pathinfo;
use function rename;
use function rmdir;
use function str_replace;
use function strrpos;
use function substr;
use function unlink;

function str_lreplace(string $search, string $replace, string $subject): string {
	// https://stackoverflow.com/a/3835653/7126351
	if(($pos = strrpos($subject, $search)) !== false) {
		$subject = substr_replace($subject, $replace, $pos, strlen($search));
	}

	return $subject;
}

class Main extends PluginBase {
	/** @var PluginManager */
	private $plMgr;
	/** @var string */
	private $myPath;

	public function onEnable(): void {
		$this->myPath = Phar::running(true) !== "" ?
			Phar::running(true) :
			dirname(__FILE__, 4) . DIRECTORY_SEPARATOR;

		$this->plMgr = $this->getServer()->getPluginManager();

		// do this on first server tick to let all perms load first
		$this->getScheduler()->scheduleTask(new ClosureTask(function (int $_): void {
			$this->doMigration();
		}));
	}

	/**
	 * @throws \ReflectionException
	 */
	private function doMigration(): void {
		$ppDir = $this->getPluginDataFolder("PurePerms");
		if(is_dir($ppDir)) {
			$ppConfig = new Config(
				$ppDir . "config.yml",
				Config::YAML
			);

			$this->getLogger()->info("Loading PurePerms group data...");
			switch($ppConfig->get("data-provider")) {
				case PPYAMLv1Source::PROVIDER_NAME:
					$ppData = new PPYAMLv1Source($ppDir);
					break;
				case PPYAMLv2Source::PROVIDER_NAME:
				default:
					$ppData = new PPYAMLv2Source($ppDir);
					break;
				case PPMySQLSource::PROVIDER_NAME:
					$ppData = new PPMySQLSource($ppConfig->get("mysql-settings", [
						"host" => "PurePerms-FTW.loveyou.all",
						"port" => "3306",
						"user" => "YourUsernameGoesHere",
						"password" => "YourPasswordGoesHere",
						"db" => "YourDBNameGoesHere",
					]));
					break;
			}

			/** @var Hierarchy $hrk */
			$hrk = $this->getServer()->getPluginManager()->getPlugin("Hierarchy");
			$roleMgr = $hrk->getRoleManager();
			$mbrFac = $hrk->getMemberFactory();

			$map = [];

			$groups = $ppData->getGroups();
			foreach($groups as $name => $groupData) {
				if($groupData["isDefault"] ?? false) {
					$role = $roleMgr->getDefaultRole();
				} else {
					$role = $roleMgr->createRole($name);
				}
				$this->getLogger()->info("Migrating '{$name}' group permissions...");
				$map[$name] = $role->getId();

				foreach($groupData["permissions"] as $permission) {
					self::procRolePermission($role, $permission);
				}
				foreach($groupData["worlds"] as $worldName => $worldData) {
					foreach($worldData["permissions"] as $permission) {
						self::procRolePermission($role, $permission);
					}
				}
			}

			foreach(
				new RecursiveDirectoryIterator(
					$this->getServer()->getDataPath() . DIRECTORY_SEPARATOR . "players",
					RecursiveDirectoryIterator::SKIP_DOTS
				) as $playerFileInfo
			) {
				$pName = pathinfo($playerFileInfo->getRealPath(), PATHINFO_FILENAME);
				$pData = $ppData->getPlayerData($pName);

				$member = $mbrFac->getMember($pName);
				$this->getLogger()->info("Migrating '{$pName}' player group...");
				if(isset($map[$pData["group"]])) { // check if the role is existent
					$role = $roleMgr->getRole($map[$pData["group"]]);
					if($role !== null && !$role->isDefault()) {
						$member->addRole($role);
					}
				}

				$this->getLogger()->info("Migrating '{$pName}' player permissions (if applicable)...");
				foreach($pData["permissions"] as $permission) {
					$t = self::ensurePermission($permission);
					if($t !== null) {
						if($t[1]) {
							$member->addMemberPermission($t[0]);
						} else {
							$member->denyMemberPermission($t[0]);
						}
					}
				}
			}

			$pcDir = $this->getPluginDataFolder("PureChat");
			$hrcDir = $this->getPluginDataFolder("HRKChat");
			if(is_dir($pcDir) && is_dir($hrcDir)){
				$pcConfig = new Config($pcDir . "config.yml", Config::YAML);
				$hrcConfig = new Config($hrcDir . "config.yml", Config::YAML);

				$hrcPrefix = $hrcConfig->getNested("placeholder.prefix");
				$hrcSuffix = $hrcConfig->getNested("placeholder.suffix");

				$replacer = function (string &$format) use ($hrcPrefix, $hrcSuffix) :void{
					$format = str_replace([
						TextFormat::ESCAPE,
						"{msg}",
						"{display_name}",
					], [
						"&",
						$hrcPrefix . "msg" . $hrcSuffix,
						$hrcPrefix . "hrk.displayName" . $hrcSuffix,
					], $format);
				};

				foreach($pcConfig->get("groups", []) as $groupName => $formats){
					$this->getLogger()->info("Migrating '{$groupName}' chat & name tag formatting...");
					if(isset($formats["chat"]) && isset($map[$groupName])){
						$hrcConfig->setNested("chatFormat." . $map[$groupName], str_replace([
							TextFormat::ESCAPE,
							"{msg}",
							"{display_name}",
						], [
							"&",
							$hrcPrefix . "msg" . $hrcSuffix,
							$hrcPrefix . "hrk.displayName" . $hrcSuffix,
						], $formats["chat"]));
					}
					if(isset($formats["nametag"]) && isset($map[$groupName])){
						$hrcConfig->setNested("nameTagFormat." . $map[$groupName], str_replace([
							TextFormat::ESCAPE,
							"{msg}",
							"{display_name}",
						], [
							"&",
							$hrcPrefix . "msg" . $hrcSuffix,
							$hrcPrefix . "hrk.displayName" . $hrcSuffix,
						], $formats["nametag"]));
					}
				}
				$hrcConfig->save();
			}

			$this->getLogger()->info("Gracefully shutting down data source...");
			$ppData->close();
			$this->disablePluginFiles("PurePerms");
			$this->disablePluginFiles("PureChat");
			$this->getLogger()->info("Migration complete, Deleting {$this->getFullName()}...");
			$this->selfDestruct();
			$this->getLogger()->info("Turning on whitelist for security purposes...");
			$this->getServer()->setConfigBool("white-list", true);
			$this->getLogger()->info("Restarting server... Please do not forget to double-check role positions.");
			$this->getServer()->shutdown();
		}
	}

	private static function procRolePermission(Role $role, string $permission): void {
		$t = self::ensurePermission($permission);
		if($t !== null) {
			if($t[1]) {
				$role->addPermission($t[0]);
			} else {
				$role->denyPermission($t[0]);
			}
		}
	}

	private function selfDestruct(): void {
		// delet_this
		if(is_dir($this->myPath)) {
			foreach(
				new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($this->myPath, RecursiveDirectoryIterator::SKIP_DOTS),
					RecursiveIteratorIterator::CHILD_FIRST
				) as $fileInfo
			) {
				if(is_dir(($path = $fileInfo->getRealPath()))) {
					rmdir($path);
				} else {
					unlink($path);
				}
			}
			rmdir($this->myPath);
		} else {
			unlink($this->myPath);
		}
	}

	private static function ensurePermission(string $perm): ?array {
		$inv = false;
		if($perm{0} === "-") {
			$perm = substr($perm, 1);
			$inv = true;
		}
		$perm = PermissionManager::getInstance()->getPermission($perm);
		if($perm instanceof Permission) {
			return [$perm, $inv];
		}

		return null;
	}

	private function getPluginDataFolder(string $pluginName): string {
		$sv = $this->getServer();
		if(!$sv->getProperty("plugins.legacy-data-dir", true)) {
			return $sv->getDataPath() . "plugin_data" . DIRECTORY_SEPARATOR . $pluginName . DIRECTORY_SEPARATOR;
		} else {
			return $sv->getDataPath() . "plugins" . DIRECTORY_SEPARATOR . $pluginName . DIRECTORY_SEPARATOR;
		}
	}

	/**
	 * @param string $pluginName
	 *
	 * @throws \ReflectionException
	 */
	private function disablePluginFiles(string $pluginName): void {
		$plugin = $this->plMgr->getPlugin($pluginName);
		if($plugin instanceof PluginBase) {
			$this->getLogger()->info("Disabling {$pluginName}...");
			$refClass = new \ReflectionClass(PluginBase::class);
			$refProp = $refClass->getProperty("file");
			$refProp->setAccessible(true);
			$path = $refProp->getValue($plugin);

			if(is_dir($path)) {
				$path .= "plugin.yml";
				rename($path, str_lreplace("yml", "ymlx", $path));
			} else {
				rename($path, str_lreplace("phar", "pharx", $path));
			}
		}
	}
}
