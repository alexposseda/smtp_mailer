<?php
    namespace core;
    
    class SMTPMail
    {
        protected $login;
        protected $password;
        protected $host;
        protected $port                     = 25;
        protected $security                 = null;
        protected $encoding                 = 'UTF-8';
        protected $xMailer                  = 'Mailer 0.1';
        protected $prefixForMessageIdHeader = '12345654321';
        
        protected $_headers = [];
        
        protected $_errors        = [];
        protected $_showException = false;
        protected $_serverAnswer  = '';
        
        protected $_connection = null;
        
        public function __construct($config, $showException = false){
            foreach($config as $prop => $value){
                if(!property_exists(__CLASS__, $prop)){
                    throw new Exception('Undefined property [' . $prop . '] in class [' . __CLASS__ . ']');
                }
                $this->$prop = $value;
            }
            
            $this->_showException = $showException;
            
            $this->init();
        }
        
        public function init(){
            $this->_headers = [
                'Date'                      => date("D, j M Y G:i:s O"),
                'X-Mailer'                  => $this->xMailer,
                'X-Priority'                => '3 (Normal)',
                'MIME-Version'              => '1.0',
                'Content-Transfer-Encoding' => '8bit',
                'Content-Type'              => 'text/html; charset=' . $this->encoding,
            ];
        }
        
        public function setHeader($key, $value){
            $this->_headers[$key] = $value;
        }
        
        public function send($from, $to, $subject, $body, $messageId = null){
            $this->clearErrors();
            $this->createConnection();
            $this->setFrom($from);
            $this->setTo($to);
            
            fputs($this->_connection, "DATA\r\n");
            if($this->getCode() != 354){
                $this->addError('Server reject DATA', true);
            }
            $this->setHeader('Subject', '=?' . $this->encoding . '?B?' . base64_encode($subject) . '=?=');
            
            $this->setMessageIdHeader($messageId);
            
            fputs($this->_connection, $this->getHeadersAsString() . "\r\n" . $body . "\r\n.\r\n");
            if($this->getCode() != 250){
                $this->addError('Cannot send email', true);
            }
            
            $this->closeConnection();
            return true;
        }
        
        protected function setFrom($from){
            if(empty($from)){
                $from = [$this->xMailer => $this->host];
            }
            
            if(is_array($from)){
                $fromName    = key($from);
                $fromAddress = current($from);
            }else{
                $fromName    = $from;
                $fromAddress = $from;
            }
            fputs($this->_connection, "MAIL FROM:<$fromAddress>\r\n");
            if($this->getCode() != 250){
                $this->addError("Server reject MAIL FROM");
            }
            $this->setHeader('From', '=?' . $this->encoding . '?B?' . base64_encode($fromName) . '=?= <' . $fromAddress . '>');
            $this->setHeader('Reply-To', '=?' . $this->encoding . '?B?' . base64_encode($fromName) . '=?= <' . $fromAddress . '>');
        }
        
        protected function setTo($to){
            if(empty($to)){
                $this->addError('To cannot be empty!', true);
            }
            
            
            if(is_array($to)){
                $toName    = key($to);
                $toAddress = current($to);
            }else{
                $toName    = $to;
                $toAddress = $to;
            }
            
            $toAddress = filter_var($toAddress,FILTER_VALIDATE_EMAIL);
            if(!$toAddress){
                $this->addError('Wrong recipient address!', true);
            }
            
            fputs($this->_connection, "RCPT TO:<$toAddress>\r\n");
            $code = $this->getCode();
            if($code != 250 AND $code != 251){
                $this->addError("Server reject RCPT TO");
            }
            $this->setHeader('To', '=?' . $this->encoding . '?B?' . base64_encode($toName) . '=?= <' . $toAddress . '>');
        }
        
        protected function setMessageIdHeader($messageId){
            if(is_null($messageId)){
                $id = date("YmjHis");
            }else{
                $id = $messageId;
            }
            $this->setHeader('Message-ID', '<' . $this->prefixForMessageIdHeader . '.' . $id . '@' . $this->host . '>');
        }
        
        protected function createConnection(){
            $errno             = 0;
            $errstr            = '';
            $hostname          = (!is_null($this->security)) ? $this->security . '://' . $this->host : $this->host;
            $this->_connection = fsockopen($hostname, $this->port, $errno, $errstr, 10);
            $data              = $this->getData();
            if(!$this->_connection){
                $this->addError('Connection failed! Errno [' . $errno . ']: ' . $errstr, true);
            }
            
            fputs($this->_connection, "EHLO " . $this->host . "\r\n");
            if($this->getCode() != 250){
                $this->addError('EHLO failed!', true);
            }
            
            fputs($this->_connection, "AUTH LOGIN\r\n");
            if($this->getCode() != 334){
                $this->addError('ServerAuth Error!', true);
            }
            
            fputs($this->_connection, base64_encode($this->login) . "\r\n");
            if($this->getCode() != 334){
                $this->addError('Auth Error! Access Denied for [' . $this->login . ']', true);
            }
            
            fputs($this->_connection, base64_encode($this->password) . "\r\n");
            if($this->getCode() != 235){
                $this->addError('Auth Error! Wrong Password!', true);
            }
        }
        
        protected function getData(){
            $data = '';
            while($str = fgets($this->_connection, 515)){
                $data .= $str;
                $this->_serverAnswer .= $str."\n";
                if(substr($str, 3, 1) == " "){
                    break;
                }
            }
//            echo $this->_serverAnswer;
            return $data;
        }
        
        protected function getCode(){
            $code = substr($this->getData(), 0, 3);
            
            return $code;
        }
        
        protected function closeConnection(){
            if(!is_bool($this->_connection)){
                fputs($this->_connection, "QUIT\r\n");
                fclose($this->_connection);
            }
        }
        
        protected function getHeadersAsString(){
            $headersString = '';
            foreach($this->_headers as $name => $value){
                $headersString .= $name . ': ' . $value . "\r\n";
            }
            
            return $headersString;
        }
        
        protected function clearErrors(){
            $this->_errors = [];
            $this->_serverAnswer = '';
        }
        
        public function addError($error, $critical = false){
            $this->_errors[] = $error;
            if($critical){
                $this->closeConnection();
                if($this->_showException){
                    throw new \Exception($error. 'Log: '.$this->_serverAnswer);
                }else{
                    echo $error . "\n" . $this->_serverAnswer;
                    die();
                }
            }
        }
        
        public function getErrors(){
            return $this->_errors;
        }
        
        public function getServerAnswer(){
            return $this->_serverAnswer;
        }
    }