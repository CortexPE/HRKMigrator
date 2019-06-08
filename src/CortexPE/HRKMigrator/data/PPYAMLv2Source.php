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

class PPYAMLv2Source extends PPYAMLv1Source implements PPDataSourceInterface {
	public const PROVIDER_NAME = "yamlv2";

	/** @var Config */
	protected $playerData;

	public function __construct(string $ppPath) {
		parent::__construct($ppPath);
		$this->playerData = new Config($ppPath . "players.yml", Config::YAML);
	}

	public function getPlayerData(string $playerName): array {
		return $this->playerData->get(strtolower($playerName), [
			"userName" => $playerName,
			"group" => $this->defaultGroup,
			"permissions" => [],
			"worlds" => [],
			"time" => -1
		]);
	}
}