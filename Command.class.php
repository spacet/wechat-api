<?php
namespace spacet\wechat;
class Command
{

	private $_cmd;

	private $_expectation = array();

	private $_unrecognized_output = "unrecognized cmd";

	private $_cmd_alias;

	public function __construct ()
	{}

	public function registerCmd ($cmd, $unrecognizedOutput = "", 
	$cmdAlias = array())
	{
		$this->_cmd = $cmd;
		$this->_unrecognized_output = ($unrecognizedOutput == "") ? $this->_unrecognized_output : $unrecognizedOutput;
		$this->_cmd_alias = (is_array($cmdAlias) && (count($cmdAlias) > 0)) ? $cmdAlias : array();
	}

	public function parseUserInput ($input, $openId)
	{
		$last_input = $this->_getLastInputByUser($openId);
		if ($last_input) {
			$this->_expectation['subcmd'] = $this->_parseLastInput($last_input);
		}
		if (isset($this->_expectation['subcmd'])) { //优先判断用户输入的是否为二级命令
			if (($input['type'] == $this->_expectation['subcmd']['type']) && (preg_match(
			$this->_expectation['subcmd']['pattern'], $input['body']))) {
				$result = call_user_func_array(
				$this->_expectation['subcmd']['callback']['func'], 
				array($openId, $input['body']));
				$log_cmd = $last_input['log_cmd'];
				$log_subcmd = $input['body'];
				$log_subcmd_order = $this->_expectation['subcmd']['order'];
			}
		}
		if (! isset($result)) { //没有匹配到合适的二级命令，继续回到一级命令
			if (isset($this->_cmd[$input['body']])) {
				$cmd = $input['body'];
			} else { //没有找到一级命令，看是否有相应的Alias
				if (isset($this->_cmd_alias[$input['body']])) {
					$cmd = $this->_cmd_alias[$input['body']];
				}
			}
			if (isset($cmd)) {
				$result = call_user_func_array(
				$this->_cmd[$cmd]['callback']['func'], array($openId, $cmd));
				$log_cmd = $cmd;
				$log_subcmd = "";
				$log_subcmd_order = 0;
			}
		}
		if (! isset($result)) { //未匹配到任何命令
			$result = array('status' => 2, 
			'output' => $this->_unrecognized_output);
		}
		if ($result['status'] == 1) {
			if (! $this->_addUserInput($log_cmd, $log_subcmd, $log_subcmd_order, 
			$openId, $log_subcmd)) {
				return false;
			}
		}
		return $result;
	}

	private function _parseLastInput ($lastInput)
	{
		$cmd = $this->_getCmdByKey($lastInput['log_cmd']);
		if ($cmd) {
			$subcmd = isset($cmd['sub']) ? $cmd['sub'] : false;
			if ($subcmd) { //上次输入有二级命令，继续track
				$key = $lastInput['log_subcmd_order'];
				if (($key + 1) <= count($subcmd)) { //上次输入还没有到二级命令尽头，继续track
					$expectation = $subcmd[$key];
					$expectation['order'] = $key + 1;
				}
			}
		}
		return $expectation;
	}

	private function _getCmdByKey ($key)
	{
		if (isset($this->_cmd[$key])) {
			return $this->_cmd[$key];
		}
		return false;
	}

	private function _getLastInputByUser ($openId)
	{
		$db = new MysqliDb("127.0.0.1", "root", "modernmedia", "wechat", 3306);
		$params = array($openId);
		$result = $db->rawQuery(
		"SELECT * FROM wechat_input_log WHERE log_openId = ? ORDER BY log_id DESC LIMIT 1", 
		$params);
		if (count($result)) {
			return $result[0];
		}
		return false;
	}

	private function _addUserInput ($log_cmd, $log_subcmd, $log_subcmd_order, 
	$log_openId, $log_content)
	{
		$db = new MysqliDb("127.0.0.1", "root", "modernmedia", "wechat", 3306);
		$insertData = array('log_cmd' => $log_cmd, 'log_subcmd' => $log_subcmd, 
		'log_subcmd_order' => $log_subcmd_order, 'log_openId' => $log_openId, 
		'log_content' => $log_content);
		if ($db->insert('wechat_input_log', $insertData)) {
			return true;
		}
		return false;
	}
}
?>