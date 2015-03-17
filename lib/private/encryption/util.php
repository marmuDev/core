<?php

/**
 * ownCloud
 *
 * @copyright (C) 2015 ownCloud, Inc.
 *
 * @author Bjoern Schiessle <schiessle@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OC\Encryption;

use OCA\Files_Encryption\Exception\EncryptionException;
use OCP\Encryption\IEncryptionModule;

class Util {

	const HEADER_ENCRYPTION_MODULE = 1;
	const HEADER_START = 'HBEGIN';
	const HEADER_END = 'HEND';
	const HEADER_PADDING_CHAR = '-';

	const HEADER_ENCRYPTION_MODULE_KEY = 'oc_encryption_module';

	/**
	 * block size will always be 8192 for a PHP stream
	 * @see https://bugs.php.net/bug.php?id=21641
	 * @var integer
	 */
	protected $headerSize = 8192;

	/**
	 * block size will always be 8192 for a PHP stream
	 * @see https://bugs.php.net/bug.php?id=21641
	 * @var integer
	 */
	protected $blockSize = 8192;

	/** @var \OC\Files\View */
	protected $view;

	/** @var array */
	protected $ocHeaderKeys;

	/** @var \OC\User\Manager */
	protected $userManager;

	/**
	 * @param \OC\Files\View $view root view
	 */
	public function __construct(\OC\Files\View $view, \OC\User\Manager $userManager) {
		$this->ocHeaderKeys = [
			self::HEADER_ENCRYPTION_MODULE => self::HEADER_ENCRYPTION_MODULE_KEY
		];

		$this->view = $view;
		$this->userManager = $userManager;
	}

	/**
	 * read encryption module ID from header
	 *
	 * @param array $header
	 * @return string|null
	 */
	public function getEncryptionModuleId(array $header) {
		$encryptionModuleKey = self::HEADER_ENCRYPTION_MODULE_KEY;

		if (isset($header[$encryptionModuleKey])) {
			return $header[$encryptionModuleKey];
		}

		return null;
	}

	/**
	 * read header into array
	 *
	 * @param string $header
	 * @return array
	 */
	public function readHeader($header) {

		$result = array();

		if (substr($header, 0, strlen(self::HEADER_START)) === self::HEADER_START) {
			$endAt = strpos($header, self::HEADER_END);
			if ($endAt !== false) {
				$header = substr($header, 0, $endAt + strlen(self::HEADER_END));

				// +1 to not start with an ':' which would result in empty element at the beginning
				$exploded = explode(':', substr($header, strlen(self::HEADER_START)+1));

				$element = array_shift($exploded);
				while ($element !== self::HEADER_END) {
					$result[$element] = array_shift($exploded);
					$element = array_shift($exploded);
				}
			}
		}

		return $result;
	}

	/**
	 * create header for encrypted file
	 *
	 * @param array $headerData
	 * @param IEncryptionModule $encryptionModule
	 * @return string
	 * @throws EncryptionException
	 */
	public function createHeader(array $headerData, IEncryptionModule $encryptionModule) {
		$header = self::HEADER_START . ':' . self::HEADER_ENCRYPTION_MODULE . ':' . $encryptionModule->getId() . ':';
		foreach ($headerData as $key => $value) {
			if (in_array($key, $this->ocHeaderKeys)) {
				throw new EncryptionException('header key "'. $key . '" already reserved by ownCloud');
			}
			$header .= $key . ':' . $value . ':';
		}
		$header .= self::HEADER_END;

		if (strlen($header) > $this->getHeaderSize()) {
			throw new EncryptionException('max header size exceeded', EncryptionException::ENCRYPTION_HEADER_TO_LARGE);
		}

		$paddedHeader = str_pad($header, $this->headerSize, self::HEADER_PADDING_CHAR, STR_PAD_RIGHT);

		return $paddedHeader;
	}

	/**
	 * Find, sanitise and format users sharing a file
	 * @note This wraps other methods into a portable bundle
	 * @param string $path path relative to current users files folder
	 * @return array
	 */
	public function getSharingUsersArray($path) {

		// Make sure that a share key is generated for the owner too
		list($owner, $ownerPath) = $this->getUidAndFilename($path);

		$ownerPath = $this->stripPartialFileExtension($ownerPath);

		// always add owner to the list of users with access to the file
		$userIds = array($owner);

		// Find out who, if anyone, is sharing the file
		$result = \OCP\Share::getUsersSharingFile($ownerPath, $owner);
		$userIds = \array_merge($userIds, $result['users']);
		$public = $result['public'] || $result['remote'];

		// check if it is a group mount
		if (\OCP\App::isEnabled("files_external")) {
			$mounts = \OC_Mount_Config::getSystemMountPoints();
			foreach ($mounts as $mount) {
				if ($mount['mountpoint'] == substr($ownerPath, 1, strlen($mount['mountpoint']))) {
					$mountedFor = $this->getUserWithAccessToMountPoint($mount['applicable']['users'], $mount['applicable']['groups']);
					$userIds = array_merge($userIds, $mountedFor);
				}
			}
		}

		// Remove duplicate UIDs
		$uniqueUserIds = array_unique($userIds);

		return array('users' => $uniqueUserIds, 'public' => $public);
	}

	/**
	 * return size of encryption header
	 *
	 * @return integer
	 */
	public function getHeaderSize() {
		return $this->headerSize;
	}

	/**
	 * return size of block read by a PHP stream
	 *
	 * @return integer
	 */
	public function getBlockSize() {
		return $this->blockSize;
	}

	public function getUidAndFilename($path) {

		$parts = explode('/', $path);
		if (count($parts) > 2) {
			$uid = $parts[1];
			if (!$this->userManager->userExists($uid)) {
				throw new \BadMethodCallException('path needs to be relative to the system wide data folder and point to a user specific file');
			}
		}

		$pathinfo = pathinfo($path);
		$partfile = false;
		$parentFolder = false;
		if (array_key_exists('extension', $pathinfo) && $pathinfo['extension'] === 'part') {
			// if the real file exists we check this file
			$filePath = $pathinfo['dirname'] . '/' . $pathinfo['filename'];
			if ($this->view->file_exists($filePath)) {
				$pathToCheck = $pathinfo['dirname'] . '/' . $pathinfo['filename'];
			} else { // otherwise we look for the parent
				$pathToCheck = $pathinfo['dirname'];
				$parentFolder = true;
			}
			$partfile = true;
		} else {
			$pathToCheck = $path;
		}

		$pathToCheck = substr($pathToCheck, strlen('/' . $uid));

		$this->view->chroot('/' . $uid);
		$owner = $this->view->getOwner($pathToCheck);

		// Check that UID is valid
		if (!\OCP\User::userExists($owner)) {
				throw new \BadMethodCallException('path needs to be relative to the system wide data folder and point to a user specific file');
		}

		\OC\Files\Filesystem::initMountPoints($owner);

		$info = $this->view->getFileInfo($pathToCheck);
		$this->view->chroot('/' . $owner);
		$ownerPath = $this->view->getPath($info->getId());
		$this->view->chroot('/');

		if ($parentFolder) {
			$ownerPath = $ownerPath . '/'. $pathinfo['filename'];
		}

		if ($partfile) {
			$ownerPath = $ownerPath . '.' . $pathinfo['extension'];
		}

		return array(
			$owner,
			\OC\Files\Filesystem::normalizePath($ownerPath)
		);
	}

	/**
	 * Remove .path extension from a file path
	 * @param string $path Path that may identify a .part file
	 * @return string File path without .part extension
	 * @note this is needed for reusing keys
	 */
	public function stripPartialFileExtension($path) {
		$extension = pathinfo($path, PATHINFO_EXTENSION);

		if ( $extension === 'part') {

			$newLength = strlen($path) - 5; // 5 = strlen(".part")
			$fPath = substr($path, 0, $newLength);

			// if path also contains a transaction id, we remove it too
			$extension = pathinfo($fPath, PATHINFO_EXTENSION);
			if(substr($extension, 0, 12) === 'ocTransferId') { // 12 = strlen("ocTransferId")
				$newLength = strlen($fPath) - strlen($extension) -1;
				$fPath = substr($fPath, 0, $newLength);
			}
			return $fPath;

		} else {
			return $path;
		}
	}

	protected function getUserWithAccessToMountPoint($users, $groups) {
		$result = array();
		if (in_array('all', $users)) {
			$result = \OCP\User::getUsers();
		} else {
			$result = array_merge($result, $users);
			foreach ($groups as $group) {
				$result = array_merge($result, \OC_Group::usersInGroup($group));
			}
		}

		return $result;
	}

	/**
	 * check if the file is stored on a system wide mount point
	 * @param string $path relative to /data/user with leading '/'
	 * @return boolean
	 */
	public function isSystemWideMountPoint($path) {
		$normalizedPath = ltrim($path, '/');
		if (\OCP\App::isEnabled("files_external")) {
			$mounts = \OC_Mount_Config::getSystemMountPoints();
			foreach ($mounts as $mount) {
				if ($mount['mountpoint'] == substr($normalizedPath, 0, strlen($mount['mountpoint']))) {
					if ($this->isMountPointApplicableToUser($mount)) {
						return true;
					}
				}
			}
		}
		return false;
	}

}
