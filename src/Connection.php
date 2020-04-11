<?php
/*
    Copyright 2020 Tamas Bolner

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

    Unless required by applicable law or agreed to in writing, software
    distributed under the License is distributed on an "AS IS" BASIS,
    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
    See the License for the specific language governing permissions and
    limitations under the License.
*/

namespace MonetDB;

use Exception;

/**
 * Class for encapsulating a connection to a MonetDB server.
 */
class Connection {
    /**
     * The maximal size of a packet to be sent over to the server through TCP.
     * 
     * @var integer
     */
    private const MAX_PACKET_SIZE = 8190;

    /**
     * DB server host name
     *
     * @var string
     */
    private $host;

    /**
     * DB server port
     *
     * @var int
     */
    private $port;

    /**
     * DB user name
     *
     * @var string
     */
    private $user;

    /**
     * DB user password
     *
     * @var string
     */
    private $password;

    /**
     * The name of the database where the user would like
     * to connect.
     *
     * @var string
     */
    private $database;

    /**
     * Lower case name of the hash algorithm, used
     * for hashing the salted password hash.
     *
     * @var string
     */
    private $saltedHashAlgo;

    /**
     * Connection socket
     *
     * @var resource|false
     */
    private $socket;

    /**
     * The classe keeping track of the responses
     * from the server.
     *
     * @var InputStream
     */
    private $inputStream;

    /**
     * An associative cache for storing the IDs of
     * prepared statements, indexed by the hash
     * of the SQL queries.
     *
     * @var array
     */
    private $preparedStatements;

    /**
     * The maximal number of tuples returned in a response.
     *
     * @var int
     */
    private $maxReplySize;

    /**
     * Create a new connection to a MonetDB database.
     * 
     * @param string $host The host of the database. Use '127.0.0.1' if the DB is on the same machine.
     * @param integer $port The port of the database. For MonetDB this is usually 50000.
     * @param string $user The user name.
     * @param string $password The password of the user.
     * @param string $database The name of the datebase to connect. Don't forget to release and start it.
     * @param string $saltedHashAlgo Optional. The preferred hash algorithm to be used for exchanging the password.
     * It has to be supported by both the server and PHP. This is only used for the salted hashing.
     * Another stronger algorithm is used first (usually SHA512).
     * @param bool $syncTimeZone If true, then tells the clients time zone offset to the server,
     * which will convert all timestamps is case there's a difference. If false, then the timestamps
     * will end up on the server unmodified.
     * @param int $maxReplySize The maximal number of tuples returned in a response. A higher value
     * results in smaller number of memory allocations and string operations, but also in
     * higher memory footprint.
     */
    function __construct(string $host, int $port, string $user, string $password, string $database,
            string $saltedHashAlgo = "SHA1", bool $syncTimeZone = true, int $maxReplySize = 200) {
        
        if (mb_internal_encoding() !== "UTF-8" || mb_regex_encoding() !== "UTF-8") {
            throw new Exception("For security reasons, this library is only allowed to be used in "
                ."PHP environments in which the multi-byte support is enabled and the default "
                ."character set is 'UTF-8'. Please set both of these: mb_internal_encoding('UTF-8'); "
                ."mb_regex_encoding('UTF-8');");
        }

        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            throw new MonetException("Unable to create socket. Received error: "
                .socket_strerror(socket_last_error()));
        }

        if (!socket_connect($this->socket, $host, $port)) {
            throw new MonetException("Unable to connect to remote host '{$host}:{$port}'."
                ." Received error: ".socket_strerror(socket_last_error()));
        }

        $this->host = trim($host);
        $this->port = (int)$port;
        $this->user = trim($user);
        $this->password = trim($password);
        $this->database = trim($database);
        $this->saltedHashAlgo = strtolower(trim($saltedHashAlgo));
        $this->maxReplySize = $maxReplySize;

        $this->inputStream = new InputStream($this, $this->socket);

        /*
            Authenticate
        */
        $this->Authenticate($user, $password);

        /*
            Syncronize time zone
        */
        if ($syncTimeZone) {
            $time_offset = date('P');
            $this->Query("SET TIME ZONE INTERVAL '{$time_offset}' HOUR TO MINUTE");
        }

        /*
            Configure the maximal number of tuples returned
            in a response.
        */
        if ($maxReplySize < 10) {
            throw new MonetException("The 'maxReplySize' parameter value of the Connection "
                ."class' constructor is too low. Please set it to at least 10.");
        }

        $this->Command("reply_size {$maxReplySize}", false);
    }

    /**
     * Authenticate with the server.
     * Throws exception in case of a failure.
     */
    private function Authenticate() {
        for ($i = 0; $i < 10; $i++) {
            $challenge = $this->inputStream->GetServerChallenge();
            
            $pwHash = $challenge->HashPassword($this->password, $this->saltedHashAlgo);
            $upperSaltHash = strtoupper($this->saltedHashAlgo);

            $this->Write("BIG:{$this->user}:{{$upperSaltHash}}{$pwHash}:sql:{$this->database}:");
            
            $this->inputStream->LoadNextResponse();
            $inputStream = $this->inputStream->ReadNextLine();
            if (InputStream::IsResponse($inputStream, InputStream::MSG_REDIRECT, "mapi:merovingian:")) {
                /*
                    Only the main process of MonetDB opens ports to listen on, and it spawns
                    separate sub-processes for each database.
                    The main process acts as a proxy and it forwards the queries to the
                    processes of the databases. For security reasons the user has to 
                    authenticate not just at the main process, but also at the
                    sub-process too. This repetition of the authentication process is
                    called a "Merovingian redirect".
                */
                continue;
            }

            if ($inputStream == InputStream::MSG_PROMPT) {
                // Successful authentication (received an empty string as a prompt)
                return;
            }

            if (InputStream::IsResponse($inputStream, InputStream::MSG_INFO, "InvalidCredentialsException:")) {
                throw(new MonetException("Authentication Failed. Invalid credentials."));
            }

            throw(new MonetException("Authentication Failed. Unexpected response from server:\n{$inputStream}\n"));
        }

        throw(new MonetException("Authentication Failed. Too many Merovingian redirects."));
    }

    /**
     * Call this at the beginning of all public methods.
     */
    private function CheckIfClosed()
    {
        if ($this->socket === false) {
            throw new MonetException("Tried to use a 'Connection' object that has been closed already.");
        }
    }

    /**
     * Close the connection
     */
    public function Close()
    {
        if ($this->socket === false) {
            return;
        }

        @socket_close($this->socket);
        $this->socket = false;
    }

    /**
     * Write a message in packets of the maximal
     * allowed size to the server.
     *
     * @param string $msg
     */
    private function Write(string $msg)
    {
        $this->CheckIfClosed();

        $chunks = str_split($msg, self::MAX_PACKET_SIZE);

        foreach($chunks as $index => $chunk) {
            $header = strlen($chunk) << 1;

            if ($index == count($chunks) - 1) {
                // Last chunk
                $header |= 1;
            }

            $chunk = pack("v", $header).$chunk;

            do {
                $written = socket_write($this->socket, $chunk);
                if ($written === false) {
                    throw new MonetException("Unable to send data to server. Connection lost. Received error: "
                        .socket_strerror(socket_last_error()));
                }

                /*
                    It's not guaranteed that 'socket_write' pushes
                    out all of the data. It returns the number of
                    bytes it has actually transmitted.
                */
                if ($written === 0) {
                    /*
                        No bytes have been written, which probably means that the
                        server has a high load. Sleep 100 ms before continue.
                    */
                    usleep(100000);
                    continue;
                }

                $chunk = substr($chunk, $written);
            } while(strlen($chunk) > 0);
        }

        if (defined("MonetDB-PHP-Deux-DEBUG")) {
            echo "OUT:\n".$this->Escape($msg)."\n";
        }
    }

    /**
     * Execute an SQL query and return its response.
     * For 'select' queries the response can be iterated
     * using a 'foreach' statement. You can pass an array as
     * second parameter to execute the query as prepared statement,
     * where the array contains the parameter values.
     * SECURITY WARNING: For prepared statements in MonetDB, the
     * parameter values are passed in a regular 'EXECUTE' command,
     * using escaping. Therefore the same security considerations
     * apply here as for using the Connection->Escape(...) method.
     * Please read the comments for that method.
     * 
     * @param string $sql
     * @param array|null $params An optional array for prepared statement parameters.
     * If not provided (or null), then a normal query is executed, instead of
     * a prepared statement. The parameter values will retain their PHP type if
     * possible. The following values won't be converted to string: null, true, false
     * and numeric values.
     * @return Response
     */
    public function Query(string $sql, array $params = null): Response
    {
        if (is_array($params)) {
            $this->WritePreparedStatement($sql, $params);
        } else {
            $this->Write("s{$sql}\n;");
        }
        
        try {
            return $this->inputStream->ReceiveResponse();
        } catch (MonetException $ex) {
            if (stripos($ex->getMessage(), "No prepared statement with id") !== false) {
                $this->ClearPsCache();
                
                throw new MonetException("When using query parameters (prepared statements), it is only allowed to"
                    ."have a single query in the SQL block. Multiple passed or syntax error.");
            }
            
            throw $ex;
        }
    }

    /**
     * Execute an SQL query and return only the first
     * row as an associative array. If there is more
     * data on the stream, then discard all.
     * Returns null if the query has empty result.
     * You can pass an array as second parameter to execute
     * the query as prepared statement, where the array
     * contains the parameter values.
     * 
     * @param string $sql
     * @param array|null $params An optional array for prepared statement parameters.
     * If not provided (or null), then a normal query is executed, instead of
     * a prepared statement. See the 'Query' method for more information about
     * the parameter values.
     * @return string[]|null
     */
    public function QueryFirst(string $sql, array $params = null): ?array
    {
        if (is_array($params)) {
            $this->WritePreparedStatement($sql, $params);
        } else {
            $this->Write("s{$sql}\n;");
        }
    
        try {
            $response = $this->inputStream->ReceiveResponse();
        } catch (MonetException $ex) {
            $this->ClearPsCache();
            
            if (stripos($ex->getMessage(), "No prepared statement with id") !== false) {
                throw new MonetException("When using query parameters (prepared statements), it is only allowed to"
                    ."have a single query in the SQL block. Multiple passed or syntax error.");
            }
            
            throw $ex;
        }

        $row = $response->Fetch();
        if (!$response->IsDiscarded()) {
            $response->Discard();
        }
        
        return $row;
    }

    /**
     * Send a 'command' to MonetDB. Commands are used for
     * configuring the database, for example setting the
     * maximal response size.
     *
     * @param string $command
     * @param bool $noResponse If true, then returns NULL and makes
     * no read to the underlying socket.
     * @return Response|null
     */
    public function Command(string $command, bool $noResponse = true): ?Response
    {
        $this->Write("X{$command}");
        if ($noResponse) {
            return null;
        }
    
        return $this->inputStream->ReceiveResponse();
    }

    /**
     * Escape a string value, to be inserted into a query,
     * inside single quotes. SECURITY WARNING:
     * Currently no successful SQL-injection attacks are known,
     * but this function was implemented without full knowledge
     * of the parsing algorithm on the server side, therefore
     * it cannot be trusted comletely. Use this library
     * only for data analysis, but don't use it for
     * authentication or session management, etc.
     * Non-authenticated users should never have the
     * opportunity to execute parameterized queries with
     * it, and never run the server as root.
     * As a security measure this library forces the use of
     * multi-byte support and UTF-8 encoding, which is also
     * used by MonetDB, avoiding the SQL-insertion attacks,
     * which play with differences between character encodings.
     * The following characters are escaped by this method:
     * backslash, single quote, carriage return,
     * line feed, tabulator, null character, CTRL+Z.
     *
     * @param string $value
     * @return string
     */
    public function Escape(string $value): string {
        /*
            - Don't use addcslashes, because that doesn't know about UTF-8
            - See: https://stackoverflow.com/a/3666326/6630230
        */
        $charArray = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $result = [];

        foreach($charArray as $c) {
            switch($c) {
                case "\\": $result[] = "\\\\"; break;
                case "'": $result[] = "\\'"; break;
                case "\r": $result[] = "\\r"; break;
                case "\n": $result[] = "\\n"; break;
                case "\t": $result[] = "\\t"; break;
                case "\0": $result[] = "\\0"; break;
                case "\x1a": $result[] = "\\032"; break;
                default: $result[] = $c;
            }
        }

        return implode($result);
    }

    /**
     * Create and execute a prepared statement.
     * Use the in-memory cache to store or retrieve it.
     *
     * @param string $sql
     * @param array $params
     */
    private function WritePreparedStatement(string $sql, array $params) {
        $queryHash = hash('sha256', $sql);

        if (!isset($this->preparedStatements[$queryHash])) {
            $this->Write("sPREPARE ".rtrim($sql, "\r\n\t ;")."\n;");
            $response = $this->inputStream->ReceiveResponse();
            $stats = $response->GetStatusRecords()[0];
            $this->preparedStatements[$queryHash] = $stats->GetPreparedStatementID();

            $response->Discard();
        }

        $id = $this->preparedStatements[$queryHash];
        $escaped = [];
        foreach($params as $param) {
            if ($param === null) {
                $escaped[] = "NULL";
            }
            else if ($param === true) {
                $escaped[] = "true";
            }
            else if ($param === false) {
                $escaped[] = "false";
            }
            elseif (is_numeric($param) && !is_string($param)) {
                $escaped[] = $param + 0;
            } else {
                $escaped[] = "'".$this->Escape((string)$param)."'";
            }
        }

        $this->Write("sEXECUTE {$id}(".implode(",", $escaped).");");
    }

    /**
     * Clears the in-memory cache of prepared statements.
     * This is called automatically when an error is
     * received from MonetDB, because that also purges
     * the prepared statements and all session state
     * in this case.
     */
    public function ClearPsCache() {
        $this->preparedStatements = [];
    }

    /**
     * The maximal number of tuples returned in a response.
     *
     * @return int
     */
    public function GetMaxReplySize(): int
    {
        return $this->maxReplySize;
    }
}