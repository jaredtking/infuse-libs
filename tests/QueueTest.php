<?php

/**
 * @package infuse\libs
 * @author Jared King <j@jaredtking.com>
 * @link http://jaredtking.com
 * @version 0.1.21.1
 * @copyright 2014 Jared King
 * @license MIT
 */

use infuse\Queue;

class QueueTest extends \PHPUnit_Framework_TestCase
{
	public function testConfigure()
	{
		Queue::configure( [
			'test' => true
		] );

		// nothing to see here
	}

	public function testType()
	{
		$q = new Queue( QUEUE_TYPE_IRON );
		$this->assertEquals( QUEUE_TYPE_IRON, $q->type() );

		$q = new Queue( 'random' );
		$this->assertEquals( QUEUE_TYPE_SYNCHRONOUS, $q->type() );
	}

	public function testEnqueueSynch()
	{
		$q = new Queue( QUEUE_TYPE_SYNCHRONOUS );

		$this->assertTrue( $q->enqueue( 'test1', 'test string!' ) );

		$this->assertTrue( $q->enqueue( 'test2', [ 'does' => 'this', 'thing' => 'work?' ] ) );

		$obj = new stdClass;
		$obj->name = 'test';
		$obj->answer = 42;
		$this->assertTrue( $q->enqueue( 'test1', $obj ) );
	}

	public function testEnqueueIron()
	{
		$q = new Queue( QUEUE_TYPE_IRON );

		$iron = Mockery::mock( 'IronMQ' );
		$iron->shouldReceive( 'postMessage' )->with( 'test1', 'message', [] )->andReturn( true )->once();
		Queue::injectIron( $iron );

		$this->assertTrue( $q->enqueue( 'test1', 'message' ) );
	}

	/**
	 * @depends testEnqueueSynch
	 */
	public function testDequeueSynch()
	{
		$q = new Queue( QUEUE_TYPE_SYNCHRONOUS );

		$test1 = $q->dequeue( 'test1' );

		$this->assertInstanceOf( 'stdClass', $test1 );
		$this->assertEquals( $test1->id, 1 );
		$this->assertEquals( $test1->body, 'test string!' );

		$messages = $q->dequeue( 'test1', 2 );

		$this->assertEquals( count( $messages ), 2 );
		$this->assertEquals( $messages[ 0 ]->id, 1 );
		$this->assertEquals( $messages[ 0 ]->body, 'test string!' );
		$this->assertEquals( $messages[ 1 ]->id, 3 );
		$expected = new stdClass;
		$expected->name = 'test';
		$expected->answer = 42;
		$this->assertEquals( $messages[ 1 ]->body, $expected );

		$test2 = $q->dequeue( 'test2' );
		$this->assertEquals( $test2->id, 2 );
		$expected = new stdClass;
		$expected->does = 'this';
		$expected->thing = 'work?';
		$this->assertEquals( $test2->body, $expected );		
	}

	public function testDequeueIron()
	{
		$q = new Queue( QUEUE_TYPE_IRON );

		$iron = Mockery::mock( 'IronMQ' );
		$iron->shouldReceive( 'getMessages' )->with( 'test1', 1 )->andReturn( [ [ 'id' => 'test', 'body' => 'message' ] ] )->once();
		$iron->shouldReceive( 'getMessages' )->with( 'test2', 1 )->andReturn( [] )->once();
		Queue::injectIron( $iron );

		$this->assertEquals( [ 'id' => 'test', 'body' => 'message' ], $q->dequeue( 'test1' ) );

		$this->assertEquals( null, $q->dequeue( 'test2' ) );
	}

	/**
	 * @depends testDequeueSynch
	 */
	public function testDeleteMessage()
	{
		$q = new Queue( QUEUE_TYPE_SYNCHRONOUS );

		$test1 = $q->dequeue( 'test1' );

		$this->assertTrue( $q->deleteMessage( 'test1', $test1 ) );

		$test1 = $q->dequeue( 'test1' );
		$this->assertEquals( $test1->id, 3 );
		$expected = new stdClass;
		$expected->name = 'test';
		$expected->answer = 42;
		$this->assertEquals( $test1->body, $expected );

		$this->assertFalse( $q->deleteMessage( 'not_found', $test1 ) );
		$this->assertFalse( $q->deleteMessage( 'test2', $test1 ) );
	}

	public function testDeleteMessageIron()
	{
		$q = new Queue( QUEUE_TYPE_IRON );

		$iron = Mockery::mock( 'IronMQ' );
		$iron->shouldReceive( 'deleteMessage' )->with( 'test1', 10 )->andReturn( true )->once();
		Queue::injectIron( $iron );

		$message = new stdClass;
		$message->id = 10;
		$this->assertTrue( $q->deleteMessage( 'test1', $message ) );
	}

	/**
	 * @depends testDeleteMessage
	 */
	public function testListeners()
	{
		$q = new Queue( QUEUE_TYPE_SYNCHRONOUS, [
				'test1' => [
					[ 'QueueMockController', 'receiveMessageListener1' ] ],
				'test2' => [
					[ 'QueueMockController', 'receiveMessageListener2' ] ] ] );

		$q->enqueue( 'test1', 'test' );
		$this->assertInstanceOf( 'stdClass', QueueMockController::$test1Message );
		$this->assertTrue( QueueMockController::$test1Message->id > 0 );
		$this->assertEquals( QueueMockController::$test1Message->body, 'test' );

		$q->enqueue( 'test2', 1234 );
		$this->assertInstanceOf( 'stdClass', QueueMockController::$test2Message );
		$this->assertTrue( QueueMockController::$test2Message->id > 0 );
		$this->assertEquals( QueueMockController::$test2Message->body, 1234 );
	}

	public function testSubscribers()
	{
		Queue::configure( [
			'queues' => [
				'test1',
				'test2' ],
			'push_subscribers' => [
				'https://example.com/',
				'https://example.com/endpoint' ],
			'auth_token' => 'testing' ] );

		$q = new Queue( QUEUE_TYPE_IRON );

		$expected = [
			'test1' => [
				[ 'url' => 'https://example.com/?q=test1&auth_token=testing' ],
				[ 'url' => 'https://example.com/endpoint?q=test1&auth_token=testing' ] ],
			'test2' => [
				[ 'url' => 'https://example.com/?q=test2&auth_token=testing' ],
				[ 'url' => 'https://example.com/endpoint?q=test2&auth_token=testing' ] ] ];
		$this->assertEquals( $expected, $q->pushQueueSubscribers() );
	}

	public function testInstallSynch()
	{
		$q = new Queue( QUEUE_TYPE_SYNCHRONOUS );
		$this->assertTrue( $q->install() );
	}

	public function testInstallIron()
	{
		Queue::configure( [
			'queues' => [
				'test1',
				'test2' ],
			'push_subscribers' => [
				'https://example.com/',
				'https://example.com/endpoint' ],
			'auth_token' => 'testing' ] );

		$iron = Mockery::mock( 'IronMQ' );
		$iron->shouldReceive( 'updateQueue' )->with( 'test1', [
			'push_type' => 'unicast',
			'subscribers' => [
				[ 'url' => 'https://example.com/?q=test1&auth_token=testing' ],
				[ 'url' => 'https://example.com/endpoint?q=test1&auth_token=testing' ] ] ] )->andReturn( true )->once();
		$iron->shouldReceive( 'updateQueue' )->with( 'test2', [
			'push_type' => 'unicast',
			'subscribers' => [
				[ 'url' => 'https://example.com/?q=test2&auth_token=testing' ],
				[ 'url' => 'https://example.com/endpoint?q=test2&auth_token=testing' ] ] ] )->andReturn( true )->once();
		Queue::injectIron( $iron );

		$q = new Queue( QUEUE_TYPE_IRON );

		$this->assertTrue( $q->install() );
	}

	public function testInstallIronFail()
	{
		Queue::configure( [
			'queues' => [
				'test1',
				'test2' ],
			'push_subscribers' => [
				'https://example.com/',
				'https://example.com/endpoint' ],
			'auth_token' => 'testing' ] );

		$iron = Mockery::mock( 'IronMQ' );
		$iron->shouldReceive( 'updateQueue' )->andReturn( false )->twice();
		Queue::injectIron( $iron );

		$q = new Queue( QUEUE_TYPE_IRON );

		$this->assertFalse( $q->install() );
	}

	public function testIronmq()
	{
		Queue::injectIron( false );
		$ironmq = Queue::ironmq();

		Queue::configure( [ 'token' => 'test', 'project_id' => 10 ] );

		$this->assertInstanceOf( '\IronMQ', $ironmq );

	}
}

class QueueMockController
{
	static $test1Message;
	static $test2Message;

	public function receiveMessageListener1( $queue, $message )
	{
		self::$test1Message = $message;

		$queue->deleteMessage( 'test1', $message );
	}

	public function receiveMessageListener2( $queue, $message )
	{
		self::$test2Message = $message;

		$queue->deleteMessage( 'test2', $message );
	}
}