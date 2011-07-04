<?php

/**
 * Пример работы с сокетами с библиотекой libevent
 * @author 440hz@php.ru
 */

class MySocket {

    /**
     * Ошибка чтения буфера
     * @var int
     */
    const EVBUFFER_READ = 0x01;

    /**
     * Ошибка буфера записи
     * @var int
     */
    const EVBUFFER_WRITE = 0x02;

    /**
     * Ошибка буфера конца файла
     * @var int
     */
    const EVBUFFER_EOF = 0x10;

    /**
     * Ошибка буфена
     * @var int
     */
    const EVBUFFER_ERROR = 0x20;

    /**
     * Ошибка буфера таймаут
     * @var int
     */
    const EVBUFFER_TIMEOUT = 0x40;

    /**
     * Пул в состоянии чтения данных
     * @var int
     */
    const POOL_READ = 0x01;

    /**
     * Пул в состоянии записи данных
     * @var int
     */
    const POOL_WRITE_CLOSE = 0x02;

    /**
     * Пул в состоянии записи данных и удержания коннекта
     * @var int
     */
    const POOL_WRITE_KEEP_ALIVE = 0x04;

    /**
     * Expect continie
     * @var int
     */
    const POOL_WRITE_EXPECT = 0x08;

    /**
     * Время в секундах ожидания ответа от клиента до закрытия коннекта
     * @var int
     */
    var $iTimeOutRead = 30;
    /**
     * Время в секундах ожидания ответа клиенту до закрытия коннекта
     * @var int
     */
    var $iTimeOutWrite = 30;

    /**
     * Общий счетчик кол-ва ошибок чтения
     * @var int
     */
    var $iErrorRead = 0;
    /**
     * Счетчик кол-ва ошибок чтения конца файла
     * @var int
     */
    var $iErrorReadEOF = 0;
    /**
     * Счетчик кол-ва ошибок чтения
     * @var int
     */
    var $iErrorReadError = 0;
    /**
     * Счетчик кол-ва ошибок таймаута
     * @var int
     */
    var $iErrorReadTimeOut = 0;
    /**
     * общий счетчик кол-ва ошибок записи
     * @var int
     */
    var $iErrorWrite = 0;
    /**
     * Счетчик кол-ва ошибок записи конца файла
     * @var int
     */
    var $iErrorWriteEOF = 0;
    /**
     * Счетчик кол-ва ошибок записи
     * @var int
     */
    var $iErrorWriteError = 0;
    /**
     * Счетчик кол-ва ошибок записи таймаута
     * @var int
     */
    var $iErrorWriteTimeOut = 0;

    /**
     * Длина буфера чтения
     * @var int
     */
    var $iBufferReadLenght = 4;

    /**
     * Счетчик коннектов
     * @var int
     */
    private $iConnection = 0;

    /**
     * массив коннектов
     * @var array
     */
    private $aConnections = array ();

    var $rBaseEvent = null;

    /**
     * ЛОГ
     * @param string $msg
     */
    private function log($msg="\n`") {
        print("{$msg}\n");
    }

    public function Start ( $sAddr = 'tcp://127.0.0.1:666' ) {

        /**
         * Открываем слушающий сокет
         * @var $rSocket resource
         */
        $this->rSocket = @stream_socket_server ( $sAddr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN );
        if ( $this->rSocket === false ) {
            die ( "Не могу открыть сокет по адресу [$sAddr].\n{$errno}:{$errstr}" );
        } else {
            $this->log("Открыт сокет по адресу: {$sAddr}");
        }

        /**
         * сдалем его не блокирующим, что б позволить еще принимать коннекты
         * @var $bRC bool
         */
        $bRC = stream_set_blocking ( $this->rSocket, 0 );
        if ( $bRC === false ) {
            die ( "Не сделать сокет неблокирующим" );
        } else {
            $this->log('Сокет разблокирован');
        }

        /*
         * Создаем базу событий
         */
        $this->rBaseEvent = event_base_new ( );
        if ( $this->rBaseEvent === false ) {
            die ( 'Не создать базу событий' );
        } else {
            $this->log('Создана база событий');
        }

        /**
         * новое событие для сокета
         * @var $event resource
         */
        $this->rSocketEvent = event_new ( );
        /**
         * ловим на чтение и после операции чтения возвращаем событие в базу
         * EV_READ - чтение
         * EV_PERSIST - вернуть событие в базу после выполнения
         *
         * @var $bRC bool
         */
        $bRc = event_set ( $this->rSocketEvent, $this->rSocket, EV_READ | EV_PERSIST, array (
                $this,
                'onAcceptEvent'
        ) );
        if ( $bRC === false ) {
            die ( "Не установить событие сокета" );
        } else {
            $this->log('Создано событие сокета');
        }

        /*
         * Кладем в базу событий
         */
        $bRc = event_base_set ( $this->rSocketEvent, $this->rBaseEvent );
        if ($bRc === false) {
            die ( "Не установить событие сокета в базу");
        } else {
            $this->log('Событие сокета установлено в базу событий');
        }

        /*
         * пускаем...
         */
        $bRc = event_add ( $this->rSocketEvent );
        if ($bRc === false) {
            die ( "Не добавить событие сокета в базу");
        } else {
            $this->log('Событие сокета добавлено в базу событий');
        }

    }

    /**
     * Прием коннекта на сокете
     * @param resource $rSocket
     * @param resource $rEvent
     * @param array $args
     */
    function onAcceptEvent ( $rSocket, $rEvent, $args ) {

        /**
         * Номер нового коннекта
         * @var $iConnect int
         */
        $iConnect = $this->iConnection ++;
        /**
         * Примем коннект
         * @var resource
         */
        $rConnection = @stream_socket_accept ( $this->rSocket );
        if ( $rConnection === false ) {
            die ( "Ошибка соединения сокета" );
        } else {
            $this->log('Соединились ['.$iConnect.']');
        }

        /**
         * сдалем его не блокирующим, что б позволить еще принимать коннекты
         * @var bool
         */
        $bRc = stream_set_blocking ( $rConnection, 0 );
        if ( $bRc === false ) {
            die ( "Не сделать коннект [{$iConnect}] на сокете не блокирующим" );
        } else {
            $this->log('Соединение ['.$iConnect.'] разблокировано');
        }

        /**
         * запомним коннект
         * @var array
         */
        $this->aConnections [ $iConnect ] = $rConnection;

        /*
         * сформируем пулы обмена
         */
        $this->aState [ $iConnect ] = self::POOL_READ;
        $this->aReadPool [ $iConnect ] = '';
        $this->aWritePool [ $iConnect ] = '';

        /*
         * создадим буфер обмена данными
         */
        $buf = event_buffer_new ( $this->aConnections [ $iConnect ], array (
                $this,
                'onReadEvent'
        ), array (
                $this,
                'onWriteEvent'
        ), array (
                $this,
                'onFailureEvent'
        ), array (
                $iConnect
        ) );
        $this->log('Буфер для соединения ['.$iConnect.'] создан');

        /* буфер в базовую наблюдалку */
        event_buffer_base_set ( $buf, $this->rBaseEvent );
        $this->log('Буфер для соединения ['.$iConnect.'] помещен в базу событий');
        /* таймауты что б рубить задержки */
        self::setTimeoutBuffer ( $buf, $this->iTimeOutRead, $this->iTimeOutWrite );
        $this->log('Буферу для соединения ['.$iConnect.'] назначены таймеры ожидания');
        /* начальные и концевые терминаторы */
        event_buffer_watermark_set ( $buf, EV_READ | EV_WRITE , 0 , 0xffffff );
        $this->log('Буферу для соединения ['.$iConnect.'] назначены концевые терминаторы');

        /* приоритет буфера */
        event_buffer_priority_set( $buf, 10 );
        $this->log('Буферу для соединения ['.$iConnect.'] установлен приоритет');

        /* включаем буфер на события и возвращаем события назад после выполнения */
        event_buffer_enable ( $buf, EV_READ | EV_WRITE | EV_PERSIST );
        $this->log('Буфер для соединения ['.$iConnect.'] включен на обработку чтения и записи');
        /* сохраним буффер */
        $this->aBuffers [$iConnect] = $buf;

    }

    /**
     * Обработка ошибок буферов ввода-вывода
     * Например по таймауту отвалить надо или сам отвалился кто
     *
     * @param $rStream Поток ввода-вывода
     * @param $iError Ошибка
     * @param $args Дополнительные аргументы
     * @return void
     */
    function onFailureEvent($rStream, $iError, $args) {

        $iConnect = $args [0];

        if ($iError & self::EVBUFFER_READ) {
            $this->iErrorRead++;
            if ($iError & self::EVBUFFER_EOF) {
                $this->iErrorReadEOF++;
            }
            if ($iError & self::EVBUFFER_ERROR) {
                $this->iErrorReadError++;
            }
            if ($iError & self::EVBUFFER_TIMEOUT) {
                $this->iErrorReadTimeOut++;
            }
        }

        if ($iError & self::EVBUFFER_WRITE) {
            $this->iErrorWrite++;
            if ($iError & self::EVBUFFER_EOF) {
                $this->iErrorWriteEOF++;
            }
            if ($iError & self::EVBUFFER_ERROR) {
                $this->iErrorWriteError++;
            }
            if ($iError & self::EVBUFFER_TIMEOUT) {
                $this->iErrorWriteTimeOut++;
            }
        }
        /* закроем коннект */
        $this->CloseConnection ( $iConnect );
    }

    /**
     * Закрыть коннект
     *
     * @param $c номер коннекта
     * @return void
     */
    function CloseConnection($iConnect) {

        $this->log('Соединение ['.$iConnect.'] закрыто');

        /* отрубим буфер */
        event_buffer_disable ( $this->aBuffers [$iConnect], EV_READ | EV_WRITE );
        /* освободим. */
        event_buffer_free ( $this->aBuffers [$iConnect] );
        unset ( $this->aBuffers [$iConnect] );

        /* закроем коннект */
        fclose ( $this->aConnections [$iConnect] );

        /* освободим */
        unset ( $this->aConnections [$iConnect] );
        unset ( $this->aState [$iConnect] );
        unset ( $this->aReadPool [$iConnect] );

    }
    /**
     * Обнулить коннект и ждать опять запрос
     *
     * @param $c номер коннекта
     * @return void
     */
    function ZeroConnection($iConnect) {

        $this->aState [$iConnect] = self::POOL_READ;
        $this->aReadPool [$iConnect] = '';

    }

    /**
     * Выдать данные и продолжить чтение. Для HTTP 101 кода
     * @param $iConnect
     */
    function ExpectConnection($iConnect) {

        $this->aState [$iConnect] = self::POOL_READ;
    }

    /**
     * Событие чтения данных
     *
     * @param $rStream
     * @param $args
     * @return void
     */
    function onReadEvent($rStream, $args) {

        $iConnect = $args [0];

        if ($this->aState [$iConnect] !== self::POOL_READ) {
            die ( "Состояние буфера[{$iConnect}] не ЧТЕНИЕ!");
            $this->CloseConnection ( $iConnect );
            return;
        }

        do {
            do {
                $tmp = self::readBuffer ( $this->aBuffers [$iConnect] );
                /* не все клиент передал. обычно по telnet так. кусками плюет. */
                if ($tmp === false) {
                    break;
                }
                if (0 == strlen($tmp)) {
                    return;
                }

                $this->log('На соединении ['.$iConnect.'] прочитано ['.strlen($tmp).'] байт');
                $this->aReadPool [$iConnect] .= $tmp;

                if( $this->iBufferReadLenght > strlen($tmp) ) {
                    break;
                }

            } while ( true );

        } while (!$this->CheckRequest ( $iConnect ) );
    }

    /**
     * Событие записи в буффер
     *
     * @param $rStream
     * @param $args
     * @return void
     */
    function onWriteEvent($rStream, $args) {

        $iConnect = $args [0];

        if ($this->aState [$iConnect] === self::POOL_WRITE_KEEP_ALIVE) {
            $this->log('На соединении ['.$iConnect.'] была запись с сохранением соединение');
            $this->ZeroConnection ( $iConnect );
            return;
        }

        if ($this->aState [$iConnect] === self::POOL_WRITE_CLOSE) {
            $this->log('На соединении ['.$iConnect.'] была запись с закрытием соединение');
            $this->CloseConnection ( $iConnect );
            return;
        }
        if ($this->aState [$iConnect] === self::POOL_WRITE_EXPECT) {
            $this->log('На соединении ['.$iConnect.'] была запись с сохранением соединение');
            $this->ExpectConnection ( $iConnect );
            return;
        }

    }

    private function CheckRequest($iConnect) {

        $buf = $this->aReadPool [$iConnect];
        $this->log('На соединении ['.$iConnect.'] анализ полученных данных');

        if( 0 < ($pos = strpos($buf,"\r\n")) ) {
            $buf = substr($buf,0,$pos);
            $this->aState [$iConnect] = self::POOL_WRITE_CLOSE;
            self::writeBuffer ( $this->aBuffers [$iConnect], 'Hello, '.$buf."\n" );
            return true;
        } else {
            return false;
        }

    }

    /**
     * Обвязка над функцией event_buffer_write
     *
     * @param $hBuffer
     * @param $sData
     * @param $iDataSize
     * @return boolean
     */
    protected static function writeBuffer ($hBuffer, $sData, $iDataSize=-1) {

        if ($iDataSize > 0) {
            $return = event_buffer_write($hBuffer, $sData, $iDataSize);
        } else {
            $return = event_buffer_write($hBuffer, $sData);
        }

        return $return;
    }

    /**
     * Обвязка над функцией event_buffer_read
     *
     * @param $hBuffer     Буфер
     * @return string    Вернет string или false
     */
    protected function readBuffer($hBuffer) {
        return event_buffer_read ( $hBuffer, $this->iBufferReadLenght );
    }

    /**
     * Обвязка над функуцией event_buffer_timeout_set
     *
     * @param $hBuffer             Буфер
     * @param $iReadTimeout     Таймаут в секундах на чтение
     * @param $iWriteTimeout    таймаут в секундах на запись
     * @return boolean
     */
    protected static function setTimeoutBuffer($hBuffer, $iReadTimeout, $iWriteTimeout) {

        return event_buffer_timeout_set($hBuffer, $iReadTimeout, $iWriteTimeout);

    }

    /**
     * запуск основного цикла обработки событий
     * @return void
     */
    function loop() {

        $bRc = event_base_loop ( $this->rBaseEvent );

        switch($bRc) {
            case -1:
                die ( 'Не могу создать очередь');
                break;
            case  1:
                die ( "нет событий в базе");
                break;
        }
    }

    /* ТАЙМЕРЫ */

    function addTimer($iTimer,$sTimerName,$aCallBack,$iInterval) {

        $this->log ( "Создание таймера [{$sTimerName}]");

        if( !is_callable($aCallBack,true,$sCallBack) OR !method_exists($aCallBack[0],$aCallBack[1])) {
            die ( "Не определен CallBack [{$sCallBack}] для таймера [{$sTimerName}]");
        } else {
            $this->log ( "Установлен таймер [{$iTimer}/{$sTimerName}] [{$sCallBack}] с интервалом [".$iInterval."] сек." );
        }
        $this->aTimers[$iTimer] = array();

        $this->aTimers[$iTimer]['name'] = $sTimerName;
        $this->aTimers[$iTimer]['interval'] = $iInterval;
        $this->aTimers[$iTimer]['callback'] = $aCallBack;


        $hTmpFile = tmpfile();
        if($hTmpFile === false) {
            die ( "Не возможно создать временный файл для таймера");
        } else {
            $this->log('Создан временный файл для таймера');
        }
        $this->aTimers[$iTimer]['tmpfile'] = $hTmpFile;

        $event = event_new();
        $this->aTimers[$iTimer]['timer'] = $event;

        $bRc = event_set(
                    $this->aTimers[$iTimer]['timer'],
                    $this->aTimers[$iTimer]['tmpfile']    ,
                    0,
                    array(  $this , 'onTimer'),
                    array ( $iTimer ));
        if ($bRc === false) {
            die ( 'Не установить событие таймера ['.$iTimer.']' );
        } else {
            $this->log('Событие таймера ['.$iTimer.'] установлено');
        }

        $bRc = event_base_set(
            $this->aTimers[$iTimer]['timer'],
            $this->rBaseEvent );
        if ($bRc === false) {
            die ( "Не установить таймер в базу");
        } else {
            $this->log('Событие таймера ['.$iTimer.'] установлено в базу');
        }

        $this->setTimer($iTimer);
    }

    function setTimer($iTimer) {

        if(!isset($this->aTimers[$iTimer])) return;

        $bRc = event_add(
                $this->aTimers[$iTimer]['timer'],
                $this->aTimers[$iTimer]['interval'] * 1000000  );
        if ($bRc === false) {
            die ( "Не добавить таймер [{$iTimer}] в базу");
        } else {
            $this->log('Таймер добавлен ['.$iTimer.'] в базу');
        }
    }

   function delTimer($iTimer,$aTimer){

        $this->log ( "Таймер [".$iTimer."] [".$aTimer['name']."] удален");
        event_free($aTimer['timer']);

    }

    /**
     * Обработчик таймеров
     * @param $rSocket
     * @param $rEvent
     * @param $a
     */
    function onTimer( $rSocket, $rEvent, $a ) {

        $iTimer = $a[0];

        call_user_func(
                $this->aTimers[$iTimer]['callback'],
                $this,
                $iTimer
        );
        $this->setTimer($iTimer);
    }
    static $iCount;
    public static function onMyTimer($handler,$iTimer) {
        print("Демон работает: ".(time() - MySocket::$iCount)." сек\n");
    }
}

$MySocket = new MySocket();
$MySocket->Start();
MySocket::$iCount = time();

$MySocket->addTimer(0,'MYTIMER',array($MySocket,'onMyTimer'),10);
$MySocket->loop();