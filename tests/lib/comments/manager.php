<?php

use OCP\Comments\ICommentsManager;

class Test_Comments_Manager extends Test\TestCase
{

	public function setUp() {
		parent::setUp();

		$sql = \oc::$server->getDatabaseConnection()->getDatabasePlatform()->getTruncateTableSQL('`*PREFIX*comments`');
		\oc::$server->getDatabaseConnection()->prepare($sql)->execute();
	}

	protected function addDatabaseEntry($parentId, $topmostParentId, $creationDT = null, $latestChildDT = null) {
		if(is_null($creationDT)) {
			$creationDT = new \DateTime();
		}
		if(is_null($latestChildDT)) {
			$latestChildDT = new \DateTime('yesterday');
		}

		$sql = '
			INSERT INTO `*PREFIX*comments`
			(
				parent_id, topmost_parent_id, children_count,
				actor_type, actor_id, message, verb, object_type, object_id,
				creation_timestamp, latest_child_timestamp
			)
			VALUES
			(
				"' . $parentId . '", "' . $topmostParentId . '", "2",
				"user", "alice", "nice one", "comment", "file", "file64",
				?, ?
			)
		';
		$stmt = \oc::$server->getDatabaseConnection()->prepare($sql);
		$stmt->bindValue(1, $creationDT, 'datetime');
		$stmt->bindValue(2, $latestChildDT, 'datetime');
		$stmt->execute();

		return \oc::$server->getDatabaseConnection()->lastInsertId('comments');
	}

	protected function getManager() {
		$factory = new \OC\Comments\ManagerFactory();
		return $factory->getManager();
	}

	public function testGetCommentNotFound() {
		$manager = $this->getManager();
		$this->setExpectedException('\OCP\Comments\NotFoundException');
		$manager->get('22');
	}

	public function testGetCommentNotFoundInvalidInput() {
		$manager = $this->getManager();
		$this->setExpectedException('\InvalidArgumentException');
		$manager->get('unexisting22');
	}

	public function testGetComment() {
		$manager = $this->getManager();

		$creationDT = new \DateTime();
		$latestChildDT = new \DateTime('yesterday');

		$sql = '
			INSERT INTO `*PREFIX*comments`
			(
				id, parent_id, topmost_parent_id, children_count,
				actor_type, actor_id, message, verb, object_type, object_id,
				creation_timestamp, latest_child_timestamp
			)
			VALUES
			(
				"3", "2", "1", "2",
				"user", "alice", "nice one", "comment", "file", "file64",
				?, ?
			)
		';
		$stmt = \oc::$server->getDatabaseConnection()->prepare($sql);
		$stmt->bindValue(1, $creationDT, 'datetime');
		$stmt->bindValue(2, $latestChildDT, 'datetime');
		$stmt->execute();

		$comment = $manager->get('3');
		$this->assertTrue($comment instanceof \OCP\Comments\IComment);
		$this->assertSame($comment->getId(), '3');
		$this->assertSame($comment->getParentId(), '2');
		$this->assertSame($comment->getTopmostParentId(), '1');
		$this->assertSame($comment->getChildrenCount(), 2);
		$this->assertSame($comment->getActorType(), 'user');
		$this->assertSame($comment->getActorId(), 'alice');
		$this->assertSame($comment->getMessage(), 'nice one');
		$this->assertSame($comment->getVerb(), 'comment');
		$this->assertSame($comment->getObjectType(), 'file');
		$this->assertSame($comment->getObjectId(), 'file64');
		$this->assertEquals($comment->getCreationDateTime(), $creationDT);
		$this->assertEquals($comment->getLatestChildDateTime(), $latestChildDT);
	}

	public function testGetTreeNotFound() {
		$manager = $this->getManager();
		$this->setExpectedException('\OCP\Comments\NotFoundException');
		$manager->getTree('22');
	}

	public function testGetTreeNotFoundInvalidIpnut() {
		$manager = $this->getManager();
		$this->setExpectedException('\InvalidArgumentException');
		$manager->getTree('unexisting22');
	}

	public function testGetTree() {
		$headId = $this->addDatabaseEntry(0, 0);

		$this->addDatabaseEntry($headId, $headId, new \DateTime('-3 hours'));
		$this->addDatabaseEntry($headId, $headId, new \DateTime('-2 hours'));
		$id = $this->addDatabaseEntry($headId, $headId, new \DateTime('-1 hour'));

		$manager = $this->getManager();
		$tree = $manager->getTree($headId);

		// Verifying the root comment
		$this->assertTrue(isset($tree['comment']));
		$this->assertTrue($tree['comment'] instanceof \OCP\Comments\IComment);
		$this->assertSame($tree['comment']->getId(), strval($headId));
		$this->assertTrue(isset($tree['replies']));
		$this->assertSame(count($tree['replies']), 3);

		// one level deep
		foreach($tree['replies'] as $reply) {
			$this->assertTrue($reply['comment'] instanceof \OCP\Comments\IComment);
			$this->assertSame($reply['comment']->getId(), strval($id));
			$this->assertSame(count($reply['replies']), 0);
			$id--;
		}
	}

	public function testGetTreeNoReplies() {
		$id = $this->addDatabaseEntry(0, 0);

		$manager = $this->getManager();
		$tree = $manager->getTree($id);

		// Verifying the root comment
		$this->assertTrue(isset($tree['comment']));
		$this->assertTrue($tree['comment'] instanceof \OCP\Comments\IComment);
		$this->assertSame($tree['comment']->getId(), strval($id));
		$this->assertTrue(isset($tree['replies']));
		$this->assertSame(count($tree['replies']), 0);

		// one level deep
		foreach($tree['replies'] as $reply) {
			throw new \Exception('This ain`t happen');
		}
	}

	public function testGetTreeWithLimitAndOffset() {
		$headId = $this->addDatabaseEntry(0, 0);

		$this->addDatabaseEntry($headId, $headId, new \DateTime('-3 hours'));
		$this->addDatabaseEntry($headId, $headId, new \DateTime('-2 hours'));
		$this->addDatabaseEntry($headId, $headId, new \DateTime('-1 hour'));
		$idToVerify = $this->addDatabaseEntry($headId, $headId, new \DateTime());

		$manager = $this->getManager();

		for ($offset = 0; $offset < 3; $offset += 2) {
			$tree = $manager->getTree(strval($headId), 2, $offset);

			// Verifying the root comment
			$this->assertTrue(isset($tree['comment']));
			$this->assertTrue($tree['comment'] instanceof \OCP\Comments\IComment);
			$this->assertSame($tree['comment']->getId(), strval($headId));
			$this->assertTrue(isset($tree['replies']));
			$this->assertSame(count($tree['replies']), 2);

			// one level deep
			foreach ($tree['replies'] as $reply) {
				$this->assertTrue($reply['comment'] instanceof \OCP\Comments\IComment);
				$this->assertSame($reply['comment']->getId(), strval($idToVerify));
				$this->assertSame(count($reply['replies']), 0);
				$idToVerify--;
			}
		}
	}

	public function testGetForObject() {
		$this->addDatabaseEntry(0, 0);

		$manager = $this->getManager();
		$comments = $manager->getForObject('file', 'file64');

		$this->assertTrue(is_array($comments));
		$this->assertSame(count($comments), 1);
		$this->assertTrue($comments[0] instanceof \OCP\Comments\IComment);
		$this->assertSame($comments[0]->getMessage(), 'nice one');
	}

	public function testGetForObjectWithLimitAndOffset() {
		$this->addDatabaseEntry(0, 0, new \DateTime('-6 hours'));
		$this->addDatabaseEntry(0, 0, new \DateTime('-5 hours'));
		$this->addDatabaseEntry(1, 1, new \DateTime('-4 hours'));
		$this->addDatabaseEntry(0, 0, new \DateTime('-3 hours'));
		$this->addDatabaseEntry(2, 2, new \DateTime('-2 hours'));
		$this->addDatabaseEntry(2, 2, new \DateTime('-1 hours'));
		$idToVerify = $this->addDatabaseEntry(3, 1, new \DateTime());

		$manager = $this->getManager();
		$offset = 0;
		do {
			$comments = $manager->getForObject('file', 'file64', 3, $offset);

			$this->assertTrue(is_array($comments));
			foreach($comments as $comment) {
				$this->assertTrue($comment instanceof \OCP\Comments\IComment);
				$this->assertSame($comment->getMessage(), 'nice one');
				$this->assertSame($comment->getId(), strval($idToVerify));
				$idToVerify--;
			}
			$offset += 3;
		} while(count($comments) > 0);
	}

	public function testGetForObjectWithDateTimeConstraint() {
		$this->addDatabaseEntry(0, 0, new \DateTime('-6 hours'));
		$this->addDatabaseEntry(0, 0, new \DateTime('-5 hours'));
		$id1 = $this->addDatabaseEntry(0, 0, new \DateTime('-3 hours'));
		$id2 = $this->addDatabaseEntry(2, 2, new \DateTime('-2 hours'));

		$manager = $this->getManager();
		$comments = $manager->getForObject('file', 'file64', 0, 0, new \DateTime('-4 hours'));

		$this->assertSame(count($comments), 2);
		$this->assertSame($comments[0]->getId(), strval($id2));
		$this->assertSame($comments[1]->getId(), strval($id1));
	}

	public function testGetForObjectWithLimitAndOffsetAndDateTimeConstraint() {
		$this->addDatabaseEntry(0, 0, new \DateTime('-7 hours'));
		$this->addDatabaseEntry(0, 0, new \DateTime('-6 hours'));
		$this->addDatabaseEntry(1, 1, new \DateTime('-5 hours'));
		$this->addDatabaseEntry(0, 0, new \DateTime('-3 hours'));
		$this->addDatabaseEntry(2, 2, new \DateTime('-2 hours'));
		$this->addDatabaseEntry(2, 2, new \DateTime('-1 hours'));
		$idToVerify = $this->addDatabaseEntry(3, 1, new \DateTime());

		$manager = $this->getManager();
		$offset = 0;
		do {
			$comments = $manager->getForObject('file', 'file64', 3, $offset, new \DateTime('-4 hours'));

			$this->assertTrue(is_array($comments));
			foreach($comments as $comment) {
				$this->assertTrue($comment instanceof \OCP\Comments\IComment);
				$this->assertSame($comment->getMessage(), 'nice one');
				$this->assertSame($comment->getId(), strval($idToVerify));
				$this->assertTrue(intval($comment->getId()) >= 4);
				$idToVerify--;
			}
			$offset += 3;
		} while(count($comments) > 0);
	}

	public function testGetNumberOfCommentsForObject() {
		for($i = 1; $i < 5; $i++) {
			$this->addDatabaseEntry(0, 0);
		}

		$manager = $this->getManager();

		$amount = $manager->getNumberOfCommentsForObject('untype', '00');
		$this->assertSame($amount, 0);

		$amount = $manager->getNumberOfCommentsForObject('file', 'file64');
		$this->assertSame($amount, 4);
	}

	public function invalidCreateArgsProvider() {
		return [
			['', 'aId-1', 'oType-1', 'oId-1'],
			['aType-1', '', 'oType-1', 'oId-1'],
			['aType-1', 'aId-1', '', 'oId-1'],
			['aType-1', 'aId-1', 'oType-1', ''],
			[1, 'aId-1', 'oType-1', 'oId-1'],
			['aType-1', 1, 'oType-1', 'oId-1'],
			['aType-1', 'aId-1', 1, 'oId-1'],
			['aType-1', 'aId-1', 'oType-1', 1],
		];
	}

	/**
	 * @dataProvider invalidCreateArgsProvider
	 */
	public function testCreateCommentInvalidArguments($aType, $aId, $oType, $oId) {
		$manager = $this->getManager();
		$this->setExpectedException('\InvalidArgumentException');
		$manager->create($aType, $aId, $oType, $oId);
	}

	public function testCreateComment() {
		$actorType = 'bot';
		$actorId = 'bob';
		$objectType = 'weather';
		$objectId = 'bielefeld';

		$comment = $this->getManager()->create($actorType, $actorId, $objectType, $objectId);
		$this->assertTrue($comment instanceof \OCP\Comments\IComment);
		$this->assertSame($comment->getActorType(), $actorType);
		$this->assertSame($comment->getActorId(), $actorId);
		$this->assertSame($comment->getObjectType(), $objectType);
		$this->assertSame($comment->getObjectId(), $objectId);
	}

	public function testDelete() {
		$manager = $this->getManager();

		$done = $manager->delete('404');
		$this->assertFalse($done);

		$done = $manager->delete('%');
		$this->assertFalse($done);

		$done = $manager->delete('');
		$this->assertFalse($done);

		$id = $this->addDatabaseEntry(0, 0);
		$comment = $manager->get($id);
		$this->assertTrue($comment instanceof \OCP\Comments\IComment);
		$done = $this->getManager()->delete($id);
		$this->assertTrue($done);
		$this->setExpectedException('\OCP\Comments\NotFoundException');
		$manager->get('1');
	}

	public function testSaveNew() {
		$manager = $this->getManager();
		$comment = new \OC\Comments\Comment();
		$comment
			->setActor('user', 'alice')
			->setObject('file', 'file64')
			->setMessage('very beautiful, I am impressed!')
			->setVerb('comment');

		$saveSuccessful = $manager->save($comment);
		$this->assertTrue($saveSuccessful);
		$this->assertTrue($comment->getId() !== '');
		$this->assertTrue($comment->getId() !== '0');
		$this->assertTrue(!is_null($comment->getCreationDateTime()));

		$loadedComment = $manager->get($comment->getId());
		$this->assertSame($comment->getMessage(), $loadedComment->getMessage());
		$this->assertEquals($comment->getCreationDateTime(), $loadedComment->getCreationDateTime());
	}

	public function testSaveUpdate() {
		$manager = $this->getManager();
		$comment = new \OC\Comments\Comment();
		$comment
				->setActor('user', 'alice')
				->setObject('file', 'file64')
				->setMessage('very beautiful, I am impressed!')
				->setVerb('comment');

		$manager->save($comment);

		$comment->setMessage('very beautiful, I am really so much impressed!');
		$manager->save($comment);

		$loadedComment = $manager->get($comment->getId());
		$this->assertSame($comment->getMessage(), $loadedComment->getMessage());
	}

	public function testSaveUpdateException() {
		$manager = $this->getManager();
		$comment = new \OC\Comments\Comment();
		$comment
				->setActor('user', 'alice')
				->setObject('file', 'file64')
				->setMessage('very beautiful, I am impressed!')
				->setVerb('comment');

		$manager->save($comment);

		$manager->delete($comment->getId());
		$comment->setMessage('very beautiful, I am really so much impressed!');
		$this->setExpectedException('\OCP\Comments\NotFoundException');
		$manager->save($comment);
	}

	public function testSaveIncomplete() {
		$manager = $this->getManager();
		$comment = new \OC\Comments\Comment();
		$comment->setMessage('from no one to nothing');
		$this->setExpectedException('\UnexpectedValueException');
		$manager->save($comment);
	}

	public function testSaveAsChild() {
		$id = $this->addDatabaseEntry(0, 0);

		$manager = $this->getManager();

		for($i = 0; $i < 3; $i++) {
			$comment = new \OC\Comments\Comment();
			$comment
					->setActor('user', 'alice')
					->setObject('file', 'file64')
					->setParentId(strval($id))
					->setMessage('full ack')
					->setVerb('comment')
					// setting the creation time avoids using sleep() while making sure to test with different timestamps
					->setCreationDateTime(new \DateTime('+' . $i . ' minutes'));

			$manager->save($comment);

			$this->assertSame($comment->getTopmostParentId(), strval($id));
			$parentComment = $manager->get(strval($id));
			$this->assertSame($parentComment->getChildrenCount(), $i + 1);
			$this->assertEquals($parentComment->getLatestChildDateTime(), $comment->getCreationDateTime());
		}
	}

	public function invalidActorArgsProvider() {
		return
		[
			['', ''],
			[1, 'alice'],
			['user', 1],
		];
	}

	/**
	 * @dataProvider invalidActorArgsProvider
	 */
	public function testDeleteReferencesOfActorInvalidInput($type, $id) {
		$manager = $this->getManager();
		$this->setExpectedException('\InvalidArgumentException');
		$manager->deleteReferencesOfActor($type, $id);
	}

	public function testDeleteReferencesOfActor() {
		$ids = [];
		$ids[] = $this->addDatabaseEntry(0, 0);
		$ids[] = $this->addDatabaseEntry(0, 0);
		$ids[] = $this->addDatabaseEntry(0, 0);

		$manager = $this->getManager();

		// just to make sure they are really set, with correct actor data
		$comment = $manager->get(strval($ids[1]));
		$this->assertSame($comment->getActorType(), 'user');
		$this->assertSame($comment->getActorId(), 'alice');

		$wasSuccessful = $manager->deleteReferencesOfActor('user', 'alice');
		$this->assertTrue($wasSuccessful);

		foreach($ids as $id) {
			$comment = $manager->get(strval($id));
			$this->assertSame($comment->getActorType(), ICommentsManager::DELETED_USER);
			$this->assertSame($comment->getActorId(), ICommentsManager::DELETED_USER);
		}

		// actor info is gone from DB, but when database interaction is alright,
		// we still expect to get true back
		$wasSuccessful = $manager->deleteReferencesOfActor('user', 'alice');
		$this->assertTrue($wasSuccessful);
	}

	public function testDeleteReferencesOfActorWithUserManagement() {
		$user = \oc::$server->getUserManager()->createUser('xenia', '123456');
		$this->assertTrue($user instanceof \OCP\IUser);

		$manager = $this->getManager();
		$comment = $manager->create('user', $user->getUID(), 'file', 'file64');
		$comment
			->setMessage('Most important comment I ever left on the Internet.')
			->setVerb('comment');
		$status = $manager->save($comment);
		$this->assertTrue($status);

		$commentID = $comment->getId();
		$user->delete();

		$comment =$manager->get($commentID);
		$this->assertSame($comment->getActorType(), \OCP\Comments\ICommentsManager::DELETED_USER);
		$this->assertSame($comment->getActorId(), \OCP\Comments\ICommentsManager::DELETED_USER);
	}

	public function invalidObjectArgsProvider() {
		return
				[
						['', ''],
						[1, 'file64'],
						['file', 1],
				];
	}

	/**
	 * @dataProvider invalidObjectArgsProvider
	 */
	public function testDeleteCommentsAtObjectInvalidInput($type, $id) {
		$manager = $this->getManager();
		$this->setExpectedException('\InvalidArgumentException');
		$manager->deleteCommentsAtObject($type, $id);
	}

	public function testDeleteCommentsAtObject() {
		$ids = [];
		$ids[] = $this->addDatabaseEntry(0, 0);
		$ids[] = $this->addDatabaseEntry(0, 0);
		$ids[] = $this->addDatabaseEntry(0, 0);

		$manager = $this->getManager();

		// just to make sure they are really set, with correct actor data
		$comment = $manager->get(strval($ids[1]));
		$this->assertSame($comment->getObjectType(), 'file');
		$this->assertSame($comment->getObjectId(), 'file64');

		$wasSuccessful = $manager->deleteCommentsAtObject('file', 'file64');
		$this->assertTrue($wasSuccessful);

		$verified = 0;
		foreach($ids as $id) {
			try {
				$manager->get(strval($id));
			} catch (\OCP\Comments\NotFoundException $e) {
				$verified++;
			}
		}
		$this->assertSame($verified, 3);

		// actor info is gone from DB, but when database interaction is alright,
		// we still expect to get true back
		$wasSuccessful = $manager->deleteCommentsAtObject('file', 'file64');
		$this->assertTrue($wasSuccessful);
	}

	public function testDeleteCommentsAtObjectWithFile() {
		$user = \oc::$server->getUserManager()->createUser('xenia', '123456');
		$this->assertTrue($user instanceof \OCP\IUser);

		$userFolder = \oc::$server->getUserFolder($user->getUID());
		$file = $userFolder->newFile('shoppinglist.txt');
		$fileId =  strval($file->getId());

		$manager = $this->getManager();
		$comment = $manager->create('user', $user->getUID(), 'file', $fileId);
		$comment
				->setMessage('Remember the toothpaste.')
				->setVerb('comment');
		$status = $manager->save($comment);
		$user->delete();
		$this->assertTrue($status);

		$commentId = $comment->getId();

		$comments = $manager->getForObject('file', $fileId);
		$this->assertTrue(count($comments) === 1);
		$this->assertSame($comments[0]->getObjectId(), $fileId);
		$this->assertSame($comments[0]->getId(), $commentId);

		$file->delete();

		$isDeleted = false;
		try {
			$manager->get($commentId);
		} catch (\OCP\Comments\NotFoundException $e) {
			$isDeleted = true;
		}
		$this->assertTrue($isDeleted);

		$comments = $manager->getForObject('file', $fileId);
		$this->assertTrue(count($comments) === 0);

	}

	public function testOverwriteDefaultManager() {
		$config = \oc::$server->getConfig();
		$defaultManagerFactory = $config->getSystemValue('comments.managerFactory', '\OC\Comments\ManagerFactory');

		$managerMock = $this->getMock('\OCP\Comments\ICommentsManager');

		$config->setSystemValue('comments.managerFactory', 'Test_Comments_FakeFactory');
		$manager = \oc::$server->getCommentsManager();
		$this->assertEquals($managerMock, $manager);

		$config->setSystemValue('comments.managerFactory', $defaultManagerFactory);
	}

}