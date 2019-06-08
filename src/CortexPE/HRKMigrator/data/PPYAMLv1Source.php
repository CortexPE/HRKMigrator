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

namespace CortexPE\HRKMigrator\data;


use function file_exists;
use pocketmine\utils\Config;
use function strtolower;
use function yaml_parse_file;

class PPYAMLv1Source implements PPDataSourceInterface {
	public const PROVIDER_NAME = "yamlv1";

	/** @var string */
	protected $ppPath;
	/** @var Config */
	protected $groupConfig;
	/** @var string */
	protected $defaultGroup = null;

	public function __construct(string $ppPath) {
		$this->ppPath = $ppPath;

		$this->groupConfig = new Config($ppPath . "groups.yml", Config::YAML);
		foreach($this->getGroups() as $name => $group){
			if($group["isDefault"]){
				$this->defaultGroup = $name;
				break;
			}
		}
	}

	public function getGroups(): array {
		return $this->groupConfig->getAll();
	}

	public function getPlayerData(string $playerName): array {
		if(file_exists(($fp = $this->ppPath . "players" . DIRECTORY_SEPARATOR . strtolower($playerName) . ".yml"))){
			return yaml_parse_file($fp);
		} else {
			return [
				"userName" => $playerName,
				"group" => $this->defaultGroup,
				"permissions" => [],
				"worlds" => [],
				"time" => -1
			];
		}
	}

	public function close(): void {
		// noop
	}
}