<?php

namespace Teddy\WebSockets;

use Nette\DI\Container;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;



class Controller implements MessageComponentInterface
{

	const METHOD_AUTHORIZE = 'authorize';
	const METHOD_NOTIFY_USERS = 'notifyUsers';
	const METHOD_EVERYONE_INCLUDING_ME = 'everyoneIncludingMe';
	const METHOD_EVERYONE_EXCLUDING_ME = 'everyoneExcludingMe';

	/**
	 * @var \SplObjectStorage
	 */
	protected $clients;

	/**
	 * @var Container
	 */
	protected $container;

	/**
	 * maps userIds to Connections (indexed by its resourceId)
	 *
	 * @var array
	 */
	protected $users = [];

	/**
	 * maps resourceId to UserId
	 *
	 * @var array
	 */
	protected $connections = [];

	/**
	 * Names of allowed methods (implemented elsewhere)
	 *
	 * @var string[]
	 */
	protected $customMethods = [];



	public function __construct(Container $container)
	{
		$this->container = $container;
		$this->clients = new \SplObjectStorage;
	}



	/**
	 * @param ConnectionInterface $conn
	 */
	public function onOpen(ConnectionInterface $conn)
	{
		$this->clients->attach($conn);
		echo "New connection! ({$conn->resourceId})\n";
	}



	/**
	 * @param ConnectionInterface $from
	 * @param int $userId
	 * @param string $apiKey
	 * @return bool
	 */
	protected function authorize(ConnectionInterface $from, $userId, $apiKey)
	{
		// you should override this function
		return TRUE;
	}



	/**
	 * Sends message to user (all his connections)
	 *
	 * @param ConnectionInterface $from
	 * @param int $userId
	 * @param array $msg
	 */
	protected function sendMsgToUser(ConnectionInterface $from, $userId, $msg)
	{
		/** @var ConnectionInterface $conn */
		foreach ($this->users[$userId] as $conn) {
			$conn->send($msg);
		}
	}



	/**
	 * Handles incoming message
	 *
	 * @param ConnectionInterface $from
	 * @param string $msg
	 */
	public function onMessage(ConnectionInterface $from, $msg)
	{
		$msg = json_decode($msg);
		$method = $msg->method;
		$data = $msg->data;

		if (!$this->isAuthorized($from) && $method !== self::METHOD_AUTHORIZE && !$this->isServer($from)) {
			echo 'User trying to send message w/o being authorized first. Terminating ' . $from->resourceId . '.' . "\n";
			$from->close();
			return;
		}

		switch ($method) {
			case self::METHOD_AUTHORIZE:
				echo 'Authorizing connection #' . $from->resourceId . ' user #' . $data->userId . "\n";
				if (!$this->authorize($from, $data->userId, $data->apiKey)) {
					echo 'Authorization failed, closing connection #' . $from->resourceId . "\n";
					$from->close();
					return;
				}
				echo 'Authorized connection #' . $from->resourceId . ' user #' . $data->userId . "\n";
				break;

			case self::METHOD_NOTIFY_USERS:
				echo 'Notifying from #' . $from->resourceId . "\n";
				foreach ($msg->users as $userId) {
					echo 'Notifying user #' . $userId . ' from #' . $from->resourceId . "\n";
					$this->sendMsgToUser($from, $userId, $data);
				}
				break;

			case self::METHOD_EVERYONE_INCLUDING_ME:
				echo 'Sending to everyone including me from #' . $from->resourceId . "\n";
				$this->sendToEveryoneIncludingMe($from, $data);
				break;

			case self::METHOD_EVERYONE_EXCLUDING_ME:
				echo 'Sending to everyone excluding me from #' . $from->resourceId . "\n";
				$this->sendToEveryoneExcludingMe($from, $data);
				break;

			default:
				if (in_array($method, $this->customMethods, TRUE)) {
					$this->$method($from, $data);

				} else {
					echo 'Unknown method ' . $method . ' from #' . $from->resourceId . "\n";
				}
		}
	}



	/**
	 * Sends message to all users (including sender)
	 *
	 * @param ConnectionInterface $from
	 * @param string $msg
	 */
	protected function sendToEveryoneIncludingMe(ConnectionInterface $from, $msg)
	{
		foreach ($this->clients as $client) {
			$client->send($msg);
		}
	}



	/**
	 * Sends message to all users (except sender)
	 *
	 * @param ConnectionInterface $from
	 * @param string $msg
	 */
	protected function sendToEveryoneExcludingMe(ConnectionInterface $from, $msg)
	{
		foreach ($this->clients as $client) {
			if ($from !== $client) {
				$client->send($msg);
			}
		}
	}


	/**
	 * Closes connection and tidies properties
	 *
	 * @param ConnectionInterface $conn
	 */
	public function onClose(ConnectionInterface $conn)
	{
		$resourceId = $conn->resourceId;

		// If the connection was authorized, delete it
		if ($this->isAuthorized($conn)) {
			$userId = $this->getUserId($conn);
			unset($this->users[$userId][$resourceId]);
			unset($this->connections[$resourceId]);
		}

		// The connection is closed, remove it, as we can no longer send it messages
		$this->clients->detach($conn);

		echo "Connection {$resourceId} has disconnected\n";
	}



	/**
	 * Logs error and closes connection
	 *
	 * @param ConnectionInterface $conn
	 * @param \Exception $e
	 */
	public function onError(ConnectionInterface $conn, \Exception $e)
	{
		echo 'An error has occurred: ' . $e->getMessage() . ', connection: ' . $conn->resourceId . "\n";

		$conn->close();
	}



	/**
	 * @param ConnectionInterface $conn
	 * @return bool
	 */
	protected function isAuthorized(ConnectionInterface $conn)
	{
		return isset($this->connections[$conn->resourceId]);
	}



	/**
	 * @param ConnectionInterface $conn
	 * @return int
	 */
	protected function getUserId(ConnectionInterface $conn)
	{
		return $this->connections[$conn->resourceId];
	}



	/**
	 * @param ConnectionInterface $conn
	 * @return bool
	 */
	protected function isServer(ConnectionInterface $conn)
	{
		return $conn->remoteAddress === '127.0.0.1';
	}

}
