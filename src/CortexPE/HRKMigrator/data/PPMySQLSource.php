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


use function explode;
use function strtolower;

class PPMySQLSource implements PPDataSourceInterface {
	public const PROVIDER_NAME = "mysql";
	/** @var \mysqli */
	protected $db;
	/** @var array */
	protected $groupsData = [];
	/** @var string */
	protected $defaultGroup = null;

	public function __construct(array $credentials) {
		$this->db = new \mysqli(
			$credentials["host"],
			$credentials["user"],
			$credentials["password"],
			$credentials["db"],
			$credentials["port"]
		);
		if($this->db->connect_error) {
			throw new \RuntimeException("Failed to connect to the PurePerms MySQL database: " . $this->db->connect_error);
		}

		$res = $this->db->query("SELECT * FROM groups;");
		while($currentRow = $res->fetch_assoc()) {
			$this->groupsData[$currentRow["groupName"]] = [
				"alias" => $currentRow["alias"],
				"isDefault" => ($isDefault = $currentRow["isDefault"] === "1" ? true : false),
				"inheritance" => !empty($currentRow["inheritance"]) ? explode(",", $currentRow["inheritance"]) : [],
				"permissions" => explode(",", $currentRow["permissions"])
			];
			if($isDefault) {
				$this->defaultGroup = $currentRow["groupName"];
			}
		}

		$res = $this->db->query("SELECT * FROM groups_mw;");
		while($currentRow = $res->fetch_assoc()) {
			$this->groupsData[$currentRow["groupName"]]["worlds"][$currentRow["worldName"]] = [
				"isDefault" => $currentRow["isDefault"] === "1" ? true : false,
				"permissions" => explode(",", $currentRow["permissions"])
			];
		}
	}

	public function getPlayerData(string $playerName): array {
		$playerName = strtolower($playerName);
		$userData = [
			"userName" => $playerName,
			"group" => $this->defaultGroup,
			"permissions" => []
		];
		$res = $this->db->query(
			"SELECT * FROM players WHERE userName = '" . $this->db->escape_string($playerName) . "';"
		);
		while($currentRow = $res->fetch_assoc()) {
			$userData["group"] = $currentRow["userGroup"];
			$userData["permissions"] = explode(",", $currentRow["permissions"]);
		}

		$res = $this->db->query(
			"SELECT * FROM players_mw WHERE userName = '" . $this->db->escape_string($playerName) . "';"
		);
		while($currentRow = $res->fetch_assoc()) {
			$userData["worlds"][$currentRow["worldName"]] = [
				"group" => $currentRow["userGroup"],
				"permissions" => explode(",", $currentRow["permissions"])
			];
		}

		return $userData;
	}

	public function getGroups(): array {
		return $this->groupsData;
	}

	public function close(): void {
		$this->db->close();
	}
}