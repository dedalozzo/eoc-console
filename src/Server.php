<?php

//! @file Server.php
//! @brief This file contains the Server class.
//! @details
//! @author Filippo F. Fadda


//! @brief This class represents the implementation of a Query Server.
//! @warning This class won't work with CGI because uses standard input (STDIN) and standard output (STDOUT).
//! @see http://wiki.apache.org/couchdb/View_server
class Server {
  const TMP_DIR = "/tmp/";
  const LOG_FILENAME = "viewserver.log";

  const EOCSVR_ERROR = "eocsvr_error";

  const EXIT_SUCCESS = 0;
  const EXIT_FAILURE = 1;

  private $fd;

  private static $commands = [];

  private $funcs;


  public final function __construct() {
    // Creates the log file descriptor.
    $this->fd = fopen(self::TMP_DIR.self::LOG_FILENAME, "w");

    // Get all available commands.
    self::scanForCommands();

    $this->funcs = [];
  }


  public final function __destruct() {
    fflush($this->fd);
    fclose($this->fd);
  }


  //! @brief Scans the commands' directory.
  //! @details CouchDB communicates with a Query Server over standard input/output. Each line represents a command.
  //! Every single command must be interpreted and executed by a specific command handler. This method scans a directory
  //! in search of every available handler.
  private static function scanForCommands() {
    foreach (glob(dirname(__DIR__)."/src/Commands/*.php") as $fileName) {
      //$className = preg_replace('/\.php\z/i', '', $fileName);
      $className = "Commands\\".basename($fileName, ".php"); // Same like the above regular expression.

      if (class_exists($className) && array_key_exists("Commands\\AbstractCmd", class_parents($className)))
        self::$commands[$className::getName()] = $className;
    }
  }


  //! @brief Starts the server.
  public final function run() {

    $this->logMsg("Server.run()");

    while ($line = trim(fgets(STDIN))) {
      @list($cmd, $args) = json_decode($line);

      if (array_key_exists($cmd, self::$commands)) {
        try {
          $className = self::$commands[$cmd];
          $cmdObj = new $className($this, $args);
          $cmdObj->execute();
        }
        catch (Exception $e) {
          $this->sendError(self::EOCSVR_ERROR, $e->getMessage());
          exit(Server::EXIT_FAILURE);
        }
      }
      else
        $this->sendError(self::EOCSVR_ERROR, "'$cmd' command is not supported.");

      fflush($this->fd);
    }


  }


  //! @brief Sends a response to CouchDB via standard output.
  //! @param[in] string $str The string to send.
  public final function writeln($str) {
    // CouchDB's message terminator is: \n.
    fputs(STDOUT, $str."\n");
    flush();
  }


  //! @brief Resets the array of the functions.
  public final function resetFuncs() {
    unset($this->funcs);
    $this->funcs = [];
  }


  //! @brief Returns the array of the functions.
  public final function getFuncs() {
    return $this->funcs;
  }


  //! @brief Add the given function to the internal functions' list.
  //! @param[in] string $fn The function implementation.
  public final function addFunc($fn) {
    $this->funcs[] = $fn;
  }


  //! @brief Tells CouchDB to append the specified message in the couch.log file.
  //! @details Any message will appear in the couch.log file, as follows:
  //!   [Tue, 22 May 2012 15:26:03 GMT] [info] [<0.80.0>] This is a log message
  //! You can't force the message's level. Every message will be marked as [info] even in case of an error, because
  //! CouchDB doesn't let you specify a different level. In case or error use <i>logError</i> instead.
  //! @warning Keep in mind that you can't use this method inside <i>reset</i> or <i>addFun</>, because you are going to
  //! generate an error. CouchDB in fact doesn't expect a message when it sends <i>reset</i> or <i>add_fun</i> commands.
  //! For debugging purpose you can use the <i>log</i> method, to write messages in a log file of your choice.
  //! @param[in] string $msg The message to store into the log file.
  public final function sendMsg($msg) {
    $this->writeln(json_encode(array("log", $msg)));
  }


  //! @brief In case of error CouchDB doesn't take any action. We simply notify the error, sending a special message to it.
  //! @param[in] string $error The error keyword.
  //! @param[in] string $reason The error message.
  public final function sendError($error, $reason) {
    $this->writeln(json_encode(array("error" => $error, "reason" => $reason)));
  }


  //! @brief Use this method when you want log something in a log file of your choice.
  //! @param[in] string $msg The log message to send CouchDB.
  public final function logMsg($msg) {
    if (empty($msg))
      fputs($this->fd, "\n");
    else
      fputs($this->fd, date("Y-m-d H:i:s")." - ".$msg."\n");
  }

}

?>