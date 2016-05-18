<?php namespace FormLister;

include_once(MODX_BASE_PATH . 'assets/lib/APIHelpers.class.php');
include_once(MODX_BASE_PATH . 'assets/lib/Helpers/FS.php');
require_once(MODX_BASE_PATH . "assets/snippets/DocLister/lib/DLTemplate.class.php");
include_once(MODX_BASE_PATH . "assets/snippets/FormLister/lib/Config.php");
include_once(MODX_BASE_PATH . "assets/snippets/FormLister/lib/Lexicon.php");

/**
 * Class FormLister
 * @package FormLister
 */
abstract class Core
{
    /**
     * @var array
     * Массив $_REQUEST
     */
    protected $_rq = array();

    protected $modx = null;

    protected $fs = null;

    /**
     * Идентификатор формы
     * @var mixed|string
     */
    protected $formid = '';

    public $config = null;

    /**
     * Шаблон для вывода по правилам DocLister
     * @var string
     */
    public $renderTpl = '';

    /**
     * Данные формы
     * fields - значения полей
     * errors - ошибки (поле => сообщение)
     * messages - сообщения
     * status - для api-режима, результат использования формы
     * @var array
     */
    private $formData = array(
        'fields'   => array(),
        'errors'   => array(),
        'messages' => array(),
        'status'   => false
    );

    protected $validator = null;

    /**
     * Массив с правилами валидации полей
     * @var array
     */
    protected $rules = array();

    /**
     * Массив с именами полей, которые можно отправлять в форме
     * По умолчанию все поля разрешены
     * @var array
     */
    public $allowedFields = array();

    /**
     * Массив с именами полей, которые запрещено отправлять в форме
     * @var array
     */
    public $forbiddenFields = array();

    protected $lexicon = null;

    /**
     * Core constructor.
     * @param \DocumentParser $modx
     * @param array $cfg
     */
    public function __construct($modx, $cfg = array())
    {
        $this->modx = $modx;
        $this->config = new \Helpers\Config($cfg);
        $this->fs = \Helpers\FS::getInstance();
        $this->lexicon = new \Helpers\Lexicon($modx, $cfg);
        if (isset($cfg['config'])) {
            $this->config->loadConfig($cfg['config']);
        }
        $this->formid = $this->getCFGDef('formid');
    }

    /**
     * Установка значений в formData
     * Установка шаблона формы
     * Загрузка капчи
     */
    public function initForm() {
        $this->allowedFields = array_filter(explode(',',$this->getCFGDef('allowedFields')));
        $this->forbiddenFields = array_filter(explode(',',$this->getCFGDef('forbiddenFields')));
        $this->setRequestParams(array_merge($_GET, $_POST));
        if (!$this->isSubmitted()) {
            $this->setExternalFields($this->getCFGDef('defaultsSources','array'));
        } else {
            if ($this->getCFGDef('keepDefaults')) $this->setExternalFields($this->getCFGDef('defaultsSources','array')); //восстановить значения по умолчанию
        }
        $this->renderTpl = $this->getCFGDef('formTpl'); //Шаблон по умолчанию
        $this->initCaptcha();
        $this->runPrepare();
    }

    /**
     * Загружает в formData данные не из формы
     * @param string $sources список источников
     * @param string $arrayParam название параметра с данными
     */
    public function setExternalFields($sources = 'array', $arrayParam = 'defaults') {
        $sources = array_filter(explode(';',$sources));
        $prefix = '';
        foreach ($sources as $source) {
            $fields = array();
            $_source = explode(':',$source);
            switch ($_source[0]) {
                case 'array':
                    if ($arrayParam) {
                        $fields = $this->config->loadArray($this->getCFGDef($arrayParam));
                    }
                    break;
                case 'param':{
                    if (isset($_source[1])) $fields = $this->config->loadArray($this->getCFGDef($_source[1]));
                    break;
                }
                case 'session':
                    $fields = isset($_source[1]) && isset($_SESSION[$_source[1]]) ?
                        $_SESSION[$_source[1]] :
                        $_SESSION;
                    $prefix = 'session';
                    break;
                case 'plh':
                    $fields = isset($_source[1]) && isset($this->modx->placeholders[$_source[1]]) ?
                        $this->modx->placeholders[$_source[1]] :
                        $this->modx->placeholders;
                    $prefix = 'plh';
                    break;
                case 'config':
                    $fields = $this->modx->config;
                    $prefix = 'config';
                    break;
                case 'cookie':
                    $fields = isset($_source[1]) && isset($_COOKIE[$_source[1]]) ?
                        $_COOKIE[$_source[1]] :
                        $_COOKIE;
                    $prefix = 'cookie';
                    break;
                default:
                    if (empty($_source[0])) break;
                    $classname = $_source[0];
                    if (class_exists($classname) && isset($_source[1])) {
                        $obj = new $classname($this->modx);
                        if ($data = $obj->edit($_source[1])) {
                            $fields = $data->toArray();
                            $prefix = $classname;
                        }
                    }
            }
            $prefix = $this->getCFGDef('extPrefix') ? $prefix : '';
            $this->setFields($fields,$prefix);
        }
    }

    /**
     * Сохранение массива $_REQUEST c фильтрацией полей
     * @param array $rq
     */
    public function setRequestParams($rq = array())
    {
        $this->_rq = $rq;
        $this->setField('formid',\APIhelpers::getkey($rq,'formid'));
        $this->setFields($this->filterFields($rq,$this->allowedFields,$this->forbiddenFields));
    }

    /**
     * Фильтрация полей по спискам разрешенных и запрещенных
     * @param array $fields
     * @param array $allowedFields
     * @param array $forbiddenFields
     * @return array
     */
    public function filterFields($fields = array(), $allowedFields = array(), $forbiddenFields = array()) {
        $out = array();
        foreach ($fields as $key => $value) {
            //список рарешенных полей существует и поле в него входит; или списка нет, тогда пофиг
            $allowed = !empty($allowedFields) ? in_array($key, $allowedFields) : true;
            //поле входит в список запрещенных полей
            $forbidden = !empty($forbiddenFields) ? in_array($key,$forbiddenFields): false;
            if (($allowed && !$forbidden) && !empty($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @return bool
     */
    public function isSubmitted() {
        $out = $this->formid && ($this->getField('formid') === $this->formid);
        return $out;
    }

    /**
     * Получение информации из конфига
     *
     * @param string $name имя параметра в конфиге
     * @param mixed $def значение по умолчанию, если в конфиге нет искомого параметра
     * @return mixed значение из конфига
     */
    public function getCFGDef($name, $def = null)
    {
        return $this->config->getCFGDef($name, $def);
    }

    /*
     * Сценарий работы
     * Если форма отправлена, то проверяем данные
     * Если проверка успешна, то обрабатываем данные
     * Выводим шаблон
     */
    public function render()
    {
        if ($this->isSubmitted()) {
            $this->validateForm();
            if ($this->isValid()) {
                $this->process();
                $this->saveObject($this->getCFGDef('saveObject'));
            }
        }
        return $this->renderForm();
    }

    /**
     * Готовит данные для вывода в шаблоне
     * @param bool $convertArraysToStrings
     * @return array
     */
    public function prerenderForm($convertArraysToStrings = false) {
        $plh = array_merge(
            $this->fieldsToPlaceholders($this->getFormData('fields'), 'value', $this->getFormData('status') || $convertArraysToStrings),
            $this->controlsToPlaceholders(),
            $this->errorsToPlaceholders(),
            array(
                'form.messages' => $this->renderMessages(),
                'captcha'=>$this->getField('captcha')
            )
        );
        return $plh;
    }

    /**
     * Вывод шаблона
     *
     * @return null|string
     */
    public function renderForm()
    {
        $api = $this->getCFGDef('api',0);
        $form = $this->parseChunk($this->renderTpl, $this->prerenderForm());
        /*
         * Если api = 0, то возвращается шаблон
         * Если api = 1, то возвращаются данные формы
         * Если api = 2, то возвращаются данные формы и шаблон
         */
        if (!$api) {
            $out = $form;
        } else {
            $out = $this->getFormData();
            if ($api == 2) $out['output'] = $form;
            $out = json_encode($out);
        }
        return $out;
    }

    /**
     * Загружает данные в formData
     * @param array $fields массив полей
     * @param string $prefix добавляет префикс к имени поля
     */
    public function setFields($fields = array(),$prefix = '')
    {
        foreach ($fields as $key => $value) {
            if ($prefix) $key = "{$prefix}.{$key}";
            $this->setField($key, $value);
        }
    }

    /**
     * Загружает класс-валидатор и создает его экземпляр
     * @return Validator|null
     */
    public function initValidator() {
        $validator = $this->getCFGDef('validator','\FormLister\Validator');
        if (!class_exists($validator)) {
            include_once(MODX_BASE_PATH . 'assets/snippets/FormLister/lib/Validator.php');
        }
        $this->validator = new $validator();
        return $this->validator;
    }

    /**
     * Возвращает результат проверки полей
     * @return bool
     */
    public function validateForm()
    {
        $validator = $this->initValidator();
        $this->getValidationRules();
        if (!$this->rules || is_null($validator)) {
            return true;
        } //если правил нет, то не проверяем

        //применяем правила
        foreach ($this->rules as $field => $rules) {
            $params = array($this->getField($field));
            foreach ($rules as $rule => $description) {
                $inverseFlag = substr($rule,0,1) == '!' ? true : false;
                if ($inverseFlag) $rule = substr($rule,1);
                $result = true;
                if (is_array($description)) {
                    if (isset($description['params'])) {
                        if (is_array($description['params'])) {
                            $params = array_merge($params,$description['params']);
                        } else {
                            $params[] = $description['params'];
                        }
                    }
                    $message = isset($description['message']) ? $description['message'] : '';
                } else {
                    $message = $description;
                }
                if (($rule != 'custom') && method_exists($validator, $rule)) {
                    $result = call_user_func_array(array($validator, $rule), $params);
                } else {
                    if (isset($description['function'])) {
                        $rule = $description['function'];
                        if ((is_object($rule) && ($rule instanceof \Closure)) || is_callable($rule)) {
                            array_unshift($params,$this);
                            $result = call_user_func_array($rule, $params);
                        }
                    }
                }
                if ($inverseFlag) $result = !$result;
                if (!$result) {
                    $this->addError(
                        $field,
                        $rule,
                        $message
                    );
                    break;
                }
            }
        }
        return $this->isValid();
    }

    /**
     * Возвращает массив formData или его часть
     * @param string $section
     * @return array
     */
    public function getFormData($section = '')
    {
        if ($section && isset($this->formData[$section])) {
            $out = $this->formData[$section];
        } else {
            $out = $this->formData;
        }
        return $out;
    }

    /**
     * Устанавливает статус формы, если true, то форма успешно обработана
     * @param $status
     */
    public function setFormStatus($status)
    {
        $this->formData['status'] = (bool)$status;
    }

    /**
     * Возвращает значение поля из formData
     * @param $field
     * @return string
     */
    public function getField($field)
    {
        return \APIhelpers::getkey($this->formData['fields'],$field);
    }

    /**
     * Сохраняет значение поля в formData
     * @param string $field имя поля
     * @param $value
     */
    public function setField($field, $value)
    {
        if (!empty($value)) $this->formData['fields'][$field] = $value;
    }

    /**
     * Добавляет в formData информацию об ошибке
     * @param string $field имя поля
     * @param string $type тип ошибки
     * @param string $message сообщение об ошибке
     */
    public function addError($field, $type, $message)
    {
        $this->formData['errors'][$field][$type] = $message;
    }

    /**
     * Добавляет сообщение в formData
     * @param string $message
     */
    public function addMessage($message = '')
    {
        if ($message) {
            $this->formData['messages'][] = $message;
        }
    }

    /**
     * Готовит данные для вывода в шаблон
     * @param array $fields массив с данными
     * @param string $suffix добавляет суффикс к имени поля
     * @param bool $split преобразование массивов в строки
     * @return array
     */
    public function fieldsToPlaceholders($fields = array(), $suffix = '', $split = false)
    {
        $plh = $fields;
        if (is_array($fields) && !empty($fields)) {
            foreach ($fields as $field => $value) {
                $field = array($field, $suffix);
                $field = implode('.', array_filter($field));
                if ($split && is_array($value)) {
                    $arraySplitter = $this->getCFGDef($field.'Splitter',$this->getCFGDef('arraySplitter','; '));
                    $value = implode($arraySplitter, $value);
                }
                $plh[$field] = \APIhelpers::e($value);
            }
        }
        return $plh;
    }

    /**
     * Готовит сообщения об ошибках для вывода в шаблон
     * @return array
     */
    public function errorsToPlaceholders()
    {
        $plh = array();
        foreach ($this->getFormData('errors') as $field => $error) {
            foreach ($error as $type => $message) {
                $classType = ($type == 'required') ? 'required' : 'error';
                $plh[$field . '.error'] = $this->parseChunk($this->getCFGDef('errorTpl',
                    '@CODE:<div class="error">[+message+]</div>'), array('message' => $message));
                $plh[$field . '.' . $classType . 'Class'] = $this->getCFGDef($field . '.' . $classType . 'Class',
                    $this->getCFGDef($classType . 'Class', $classType));
            }
        }
        return $plh;
    }

    /**
     * Обработка чекбоксов, селектов, радио-кнопок перед выводом в шаблон
     * @return array
     */
    public function controlsToPlaceholders()
    {
        $plh = array();
        $formControls = explode(',',$this->getCFGDef('formControls'));
        foreach ($formControls as $field) {
            $value = $this->getField($field);
            if (empty($value)) {
                continue;
            } elseif (is_array($value)) {
                foreach ($value as $_value) {
                    $plh["s.{$field}.{$_value}"] = 'selected';
                    $plh["c.{$field}.{$_value}"] = 'checked';
                }
            } else {
                $plh["s.{$field}.{$value}"] = 'selected';
                $plh["c.{$field}.{$value}"] = 'checked';
            }
        }
        return $plh;
    }

    /**
     * Загрузка правил валидации
     */
    public function getValidationRules()
    {
        $rules = $this->getCFGDef('rules', '');
        $rules = $this->config->loadArray($rules);
        if ($rules) $this->rules = array_merge($this->rules,$rules);
    }

    /**
     * Готовит сообщения из formData для вывода в шаблон
     * @return null|string
     */
    public function renderMessages()
    {
        $out = '';
        $formMessages = $this->getFormData('messages');
        $formErrors = $this->getFormData('errors');

        $requiredMessages = $errorMessages = array();
        if ($formErrors) {
            foreach ($formErrors as $field => $error) {
                $type = key($error);
                if ($type == 'required') {
                    $requiredMessages[] = $error[$type];
                } else {
                    $errorMessages[] = $error[$type];
                }
            }
        }
        $wrapper = $this->getCFGDef('messagesTpl', '@CODE:<div class="form-messages">[+messages+]</div>');
        if (!empty($formMessages) || !empty($formErrors)) {
            $out = $this->parseChunk($wrapper,
                array(
                    'messages' => $this->renderMessagesGroup(
                        $formMessages,
                        'messagesOuterTpl',
                        'messagesSplitter'),
                    'required' => $this->renderMessagesGroup(
                        $requiredMessages,
                        'messagesRequiredOuterTpl',
                        'messagesRequiredSplitter'),
                    'errors'  => $this->renderMessagesGroup(
                        $errorMessages,
                        'messagesErrorOuterTpl',
                        'messagesErrorSplitter'),
                ));
        }
        return $out;
    }

    public function renderMessagesGroup($messages, $wrapper, $splitter)
    {
        $out = '';
        if (is_array($messages) && !empty($messages)) {
            $out = implode($this->getCFGDef($splitter,'<br>'), $messages);
            $wrapperChunk = $this->getCFGDef($wrapper, '@CODE: [+messages+]');
            $out = $this->parseChunk($wrapperChunk, array('messages' => $out));
        }
        return $out;
    }

    public function parseChunk($name, $data, $parseDocumentSource = false)
    {
        $out = null;
        $out = \DLTemplate::getInstance($this->modx)->parseChunk($name, $data, $parseDocumentSource);
        if ($this->lexicon->isReady()) $out = $this->lexicon->parseLang($out);
        return $out;
    }

    /**
     * Загружает класс капчи
     */
    public function initCaptcha()
    {
        if ($captcha = $this->getCFGDef('captcha')) {
            $wrapper = MODX_BASE_PATH . "assets/snippets/FormLister/lib/captcha/{$captcha}/wrapper.php";
            if ($this->fs->checkFile($wrapper)) {
                include_once($wrapper);
                $wrapper = $captcha.'Wrapper';
                $captcha = new $wrapper ($this);
                $this->rules[$this->getCFGDef('captchaField', 'vericode')] = $captcha->getRule();
                $this->setField('captcha',$captcha->getPlaceholder());
            }
        }
    }

    public function getMODX() {
        return $this->modx;
    }

    public function getFormId() {
        return $this->formid;
    }

    public function isValid() {
        return !count($this->getFormData('errors'));
    }

    /**
     * @param string $name
     */
    public function saveObject($name = '') {
        if ($name) $this->modx->setPlaceholder($this->getCFGDef($name),$this);
    }

    public function runPrepare() {
        if (($prepare = $this->getCFGDef('prepare')) != '') {
            if(is_scalar($prepare)){
                $names = explode(",", $prepare);
                foreach($names as $item){
                    $this->callPrepare($item);
                }
            }else{
                $this->callPrepare($prepare);
            }
        }
    }

    public function callPrepare($name) {
        if (empty($name)) return;
        if((is_object($name) && ($name instanceof \Closure)) || is_callable($name)){
            call_user_func($name, $this);
        }else{
            $params = array(
                'modx' => $this->modx,
                'FormLister' => $this
            );
            $this->modx->runSnippet($name, $params);
        }
    }

    /**
     * @param string $param имя параметра с id документа для редиректа
     * В api-режиме редирект не выполняется, но ссылка доступна в formData
     */
    public function redirect($param = 'redirectTo') {
        if ($redirect = $this->getCFGDef($param,0)) {
            $redirect = $this->modx->makeUrl($redirect,'','','full');
            $this->setField($param, $redirect);
            if (!$this->getCFGDef('api',0)) $this->modx->sendRedirect($redirect, 0, 'REDIRECT_HEADER', 'HTTP/1.1 307 Temporary Redirect');
        }
    }

    /**
     * Обработка формы, определяется контроллерами
     *
     * @return mixed
     */
    abstract public function process();
}
