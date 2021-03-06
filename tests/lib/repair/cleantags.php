<?php
/**
 * Copyright (c) 2015 Joas Schilling <nickvergessen@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test\Repair;

/**
 * Tests for the cleaning the tags tables
 *
 * @see \OC\Repair\CleanTags
 */
class CleanTags extends \Test\TestCase {

	/** @var \OC\RepairStep */
	protected $repair;

	/** @var \Doctrine\DBAL\Connection */
	protected $connection;

	/** @var int */
	protected $createdFile;

	protected function setUp() {
		parent::setUp();

		$this->connection = \OC::$server->getDatabaseConnection();
		$this->repair = new \OC\Repair\CleanTags($this->connection);
		$this->cleanUpTables();
	}

	protected function tearDown() {
		$this->cleanUpTables();

		parent::tearDown();
	}

	protected function cleanUpTables() {
		$qb = $this->connection->createQueryBuilder();
		$qb->delete('`*PREFIX*vcategory`')
			->execute();

		$qb->delete('`*PREFIX*vcategory_to_object`')
			->execute();

		$qb->delete('`*PREFIX*filecache`')
			->execute();
	}

	public function testRun() {
		$cat1 = $this->addTagCategory('TestRepairCleanTags', 'files'); // Retained
		$cat2 = $this->addTagCategory('TestRepairCleanTags2', 'files'); // Deleted: Category will be empty
		$this->addTagCategory('TestRepairCleanTags3', 'files'); // Deleted: Category is empty
		$cat3 = $this->addTagCategory('TestRepairCleanTags', 'contacts'); // Retained

		$this->addTagEntry($this->getFileID(), $cat2, 'files'); // Retained
		$this->addTagEntry($this->getFileID() + 1, $cat1, 'files'); // Deleted: File is NULL
		$this->addTagEntry(9999999, $cat3, 'contacts'); // Retained
		$this->addTagEntry($this->getFileID(), $cat3 + 1, 'files'); // Deleted: Category is NULL

		$this->assertEntryCount('*PREFIX*vcategory_to_object', 4, 'Assert tag entries count before repair step');
		$this->assertEntryCount('*PREFIX*vcategory', 4, 'Assert tag categories count before repair step');

		\Test_Helper::invokePrivate($this->repair, 'deleteOrphanFileEntries');
		$this->assertEntryCount('*PREFIX*vcategory_to_object', 3, 'Assert tag entries count after cleaning file entries');
		$this->assertEntryCount('*PREFIX*vcategory', 4, 'Assert tag categories count after cleaning file entries');

		\Test_Helper::invokePrivate($this->repair, 'deleteOrphanTagEntries');
		$this->assertEntryCount('*PREFIX*vcategory_to_object', 2, 'Assert tag entries count after cleaning tag entries');
		$this->assertEntryCount('*PREFIX*vcategory', 4, 'Assert tag categories count after cleaning tag entries');

		\Test_Helper::invokePrivate($this->repair, 'deleteOrphanCategoryEntries');
		$this->assertEntryCount('*PREFIX*vcategory_to_object', 2, 'Assert tag entries count after cleaning category entries');
		$this->assertEntryCount('*PREFIX*vcategory', 2, 'Assert tag categories count after cleaning category entries');
	}

	/**
	 * @param string $tableName
	 * @param int $expected
	 * @param string $message
	 */
	protected function assertEntryCount($tableName, $expected, $message = '') {
		$qb = $this->connection->createQueryBuilder();
		$result = $qb->select('COUNT(*)')
			->from('`' . $tableName . '`')
			->execute();

		$this->assertEquals($expected, $result->fetchColumn(), $message);
	}

	/**
	 * Adds a new tag category to the database
	 *
	 * @param string $category
	 * @param string $type
	 * @return int
	 */
	protected function addTagCategory($category, $type) {
		$qb = $this->connection->createQueryBuilder();
		$qb->insert('`*PREFIX*vcategory`')
			->values([
				'`uid`'			=> $qb->createNamedParameter('TestRepairCleanTags'),
				'`category`'	=> $qb->createNamedParameter($category),
				'`type`'		=> $qb->createNamedParameter($type),
			])
			->execute();

		return (int) $this->getLastInsertID('`*PREFIX*vcategory`', '`id`');
	}

	/**
	 * Adds a new tag entry to the database
	 * @param int $objectId
	 * @param int $category
	 * @param string $type
	 */
	protected function addTagEntry($objectId, $category, $type) {
		$qb = $this->connection->createQueryBuilder();
		$qb->insert('`*PREFIX*vcategory_to_object`')
			->values([
				'`objid`'		=> $qb->createNamedParameter($objectId, \PDO::PARAM_INT),
				'`categoryid`'	=> $qb->createNamedParameter($category, \PDO::PARAM_INT),
				'`type`'		=> $qb->createNamedParameter($type),
			])
			->execute();
	}

	/**
	 * Gets the last fileid from the file cache
	 * @return int
	 */
	protected function getFileID() {
		if ($this->createdFile) {
			return $this->createdFile;
		}

		$qb = $this->connection->createQueryBuilder();

		// We create a new file entry and delete it after the test again
		$fileName = $this->getUniqueID('TestRepairCleanTags', 12);
		$qb->insert('`*PREFIX*filecache`')
			->values([
				'`path`'			=> $qb->createNamedParameter($fileName),
				'`path_hash`'		=> $qb->createNamedParameter(md5($fileName)),
			])
			->execute();
		$fileName = $this->getUniqueID('TestRepairCleanTags', 12);
		$qb->insert('`*PREFIX*filecache`')
			->values([
				'`path`'			=> $qb->createNamedParameter($fileName),
				'`path_hash`'		=> $qb->createNamedParameter(md5($fileName)),
			])
			->execute();

		$this->createdFile = (int) $this->getLastInsertID('`*PREFIX*filecache`', '`fileid`');
		return $this->createdFile;
	}

	/**
	 * @param $tableName
	 * @param $idName
	 * @return int
	 */
	protected function getLastInsertID($tableName, $idName) {
		$id = $this->connection->lastInsertId();
		if ($id) {
			return $id;
		}

		// FIXME !!!! ORACLE WORKAROUND DO NOT COPY
		// FIXME INSTEAD HELP FIXING DOCTRINE
		// FIXME https://github.com/owncloud/core/issues/13303
		// FIXME ALSO FIX https://github.com/owncloud/core/commit/2dd85ec984c12d3be401518f22c90d2327bec07a
		$qb = $this->connection->createQueryBuilder();
		$result = $qb->select("MAX($idName)")
			->from($tableName)
			->execute();

		return (int) $result->fetchColumn();
	}
}
