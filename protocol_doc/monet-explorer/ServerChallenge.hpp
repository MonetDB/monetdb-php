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
#pragma once

#include <string>
#include <unordered_set>
#include <vector>
#include <sstream>
#include <openssl/sha.h>


namespace MonetExplorer {
    /**
     * @brief Parse a "server challenge" line, which
     * can be received multiple time from the server
     * during authentication.
     */
    class ServerChallenge {
        private:
            std::string salt;
            std::string backend;
            int version;
            std::unordered_set<std::string> protocols;
            std::string endianness;
            std::string passwordHashAlgo;
            const char *hexa = "0123456789abcdef";
            char *bufferBin = 0;
            char *bufferHex = 0;

            /**
             * @brief Converts binary data to hex.
             * 
             * @param source The source buffer.
             * @param size The size of the source buffer.
             * @param dest The destination buffer. It requires double the size. (2 x size)
             */
            void BinToHex(char *source, int size, char *dest) {
                for (int i = 0; i < size; i++) {
                    dest[2 * i + 1] = hexa[source[i] & 15];
                    dest[2 * i] = hexa[(source[i] >> 4) & 15];
                }
            }

            /**
             * @brief SHA512 hash
             * 
             * @param data Input
             * @return std::string Output
             */
            std::string Sha512(std::string data) {
                SHA512((unsigned char*)data.c_str(), data.length(), (unsigned char*)this->bufferBin);
                this->BinToHex(this->bufferBin, 64, this->bufferHex);

                return std::string(this->bufferHex, 128);
            }

            /**
             * @brief SHA256 hash
             * 
             * @param data Input
             * @return std::string Output
             */
            std::string Sha256(std::string data) {
                SHA256((unsigned char*)data.c_str(), data.length(), (unsigned char*)this->bufferBin);
                this->BinToHex(this->bufferBin, 32, this->bufferHex);

                return std::string(this->bufferHex, 64);
            }

            /**
             * @brief SHA1 hash
             * 
             * @param data Input
             * @return std::string Output
             */
            std::string Sha1(std::string data) {
                SHA1((unsigned char*)data.c_str(), data.length(), (unsigned char*)this->bufferBin);
                this->BinToHex(this->bufferBin, 20, this->bufferHex);

                return std::string(this->bufferHex, 40);
            }

        public:
            /**
             * @brief Construct a new Server Challenge object
             * 
             * @param msg The message received from the server.
             */
            ServerChallenge(std::string &msg) : salt(), backend(), version(0), protocols(),
                    endianness(), passwordHashAlgo() {

                if (msg.length() < 1) {
                    throw std::runtime_error("Empty message received. Expected server challenge.");
                }

                this->bufferBin = new char[64];
                this->bufferHex = new char[128];
                
                const char *pos = msg.c_str();
                const char *start = pos;
                const char *endPos = pos + msg.length() - 1;
                int field = 0;

                for(; pos < endPos; pos++) {
                    if (*pos == ':' || pos == endPos - 1 || *pos == '\n' || *pos == ',') {
                        switch (field) {
                            case 0: {
                                this->salt = std::string(start, pos - start);
                                if (this->salt.length() < 6) {
                                    throw std::runtime_error("Too short salt value received "
                                        "in the server challenge line: " + this->salt);
                                }
                                break;
                            }
                            case 1: {
                                this->backend = std::string(start, pos - start);
                                if (this->backend != "merovingian" && this->backend != "monetdb"
                                        && this->backend != "mserver") {
                                    
                                    throw std::runtime_error("Invalid backend value received "
                                        "in the server challenge line: " + this->backend);
                                }
                                break;
                            }
                            case 2: {
                                std::string tmp(start, pos - start);
                                char *endPtr;
                                this->version = strtol(tmp.c_str(), &endPtr, 10);
                                if (errno != 0 || *endPtr != '\0') {
                                    throw std::runtime_error("Invalid version value received "
                                        "in the server challenge line: " + tmp);
                                }
                                
                                break;
                            }
                            case 3: {
                                std::string proto(start, pos - start);
                                if (proto.length() < 1) {
                                    throw std::runtime_error("Invalid protocol name received "
                                        "in the server challenge line. (empty value)");
                                }
                                this->protocols.insert(proto);
                                break;
                            }
                            case 4: {
                                this->endianness = std::string(start, pos - start);
                                if (this->endianness != "LIT") {
                                    throw std::runtime_error("The server challenge line offered "
                                        "endianness '" + this->endianness + "', but only "
                                        "LIT (little endian) is accepted.");
                                }
                                break;
                            }
                            case 5: {
                                this->passwordHashAlgo = std::string(start, pos - start);
                                if (this->passwordHashAlgo.length() < 1) {
                                    throw std::runtime_error("Invalid password hash algo received "
                                        "in the server challenge line. (empty value)");
                                }
                                break;
                            }
                        }

                        if (*pos == ':') {
                            if (field >= 5) {
                                break;
                            }

                            field++;
                        }

                        start = pos + 1;
                    }
                }

                if (field < 5) {
                    throw std::runtime_error("The server challenge line contained less than 5 fields.");
                }
            }

            /**
             * @brief Destroy the Server Challenge object
             */
            ~ServerChallenge() {
                if (this->bufferBin != 0) {
                    delete[] this->bufferBin;
                }

                if (this->bufferHex != 0) {
                    delete[] this->bufferHex;
                }
            }

            /**
             * @brief Generates the response message to the server
             * challenge, for the authentication.
             * 
             * @param user MonetDB user name.
             * @param password User password.
             * @param database The name of the database to connect to.
             * @param proto The protocol to be used. Currently supported: SHA1, SHA256, SHA512
             * @param enableFileTransfer Request for enabling the file transfer feature.
             *      (Transferring CSV files directly in the client-server connection, unparsed.)
             * @return std::string 
             */
            std::string Authenticate(std::string user, std::string password, std::string database,
                    std::string proto, bool enableFileTransfer) {
                
                if (this->protocols.find(proto) == this->protocols.end()) {
                    throw std::runtime_error("The protocol '" + proto + "' chosen from the command line "
                        "is not supported by the server. (Please check if it's upper-case.)");
                }
                
                std::stringstream buff;
                std::string pwHash = this->Sha1(this->Sha512(password) + this->salt);
                
                buff << this->endianness << ':' << user << ':' << "{SHA1}" << pwHash 
                    << ":sql:" << database << ':';
                
                if (enableFileTransfer) {
                    buff << "FILETRANS";
                }
                
                buff  << "\n";

                return buff.str();
            }
    };
}