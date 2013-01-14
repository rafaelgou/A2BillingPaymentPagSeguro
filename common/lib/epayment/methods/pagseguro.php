<?php
include(dirname(__FILE__).'/../includes/methods/pagseguro.php');

class pagseguro {
  var $code, $title, $description, $enabled;

  function pagseguro() 
  {
      $this->code = 'pagseguro';
      $this->title = MODULE_PAYMENT_PAGSEGURO_TEXT_TITLE;
      $this->description = MODULE_PAYMENT_PAGSEGURO_TEXT_DESCRIPTION;
      $this->sort_order = 1;
      $this->enabled = ((MODULE_PAYMENT_PAGSEGURO_STATUS == 'True') ? true : false);
      $this->form_action_url = $GLOBALS['A2B']->config["epayment_method"]["pagseguro_payment_url"];
  }

  function process_button($transactionID = 0, $key= "")
  {
    global $order, $currencies, $currency;

      $my_currency = 'BRL';
      $currencyObject = new currencies();
      $value = number_format($order->info['total']/$currencyObject->get_value($my_currency), $currencyObject->get_decimal_places($my_currency));
      $process_button_string = tep_draw_hidden_field('cmd', '_xclick')
                   . tep_draw_hidden_field('identPedido', $transactionID)
                   . tep_draw_hidden_field('VlrTotal', $value)
                   ;
      return $process_button_string;
  }
  
  function getStatusA2BDescription($status)
  {
    $status = (int) $status;
    switch($status)
    {
      case -2: return "Failed";      break;
      case -1: return "Denied";      break;
      case  0: return "Pending";     break;
      case  1: return "In-Progress"; break;
      case  2: return "Completed";   break;
      case  3: return "Processed";   break;
      case  4: return "Refunded";    break;
      default: return "";            break;
    }    
  }
  
  static function getStatusPagSeguroDescriptionBR($status)
  {
    $status = (int) $status;
    switch($status)
    {
    case 'PAID':            return 'PAGO'; break;
    case 'AVAILABLE':       return 'DISPONÍVEL'; break;
    case 'WAITING_PAYMENT': return 'AGUARDANDO PAGAMENTO'; break;
    case 'IN_ANALYSIS':     return 'EM ANÁLISE'; break;
    case 'IN_DISPUTE':      return 'EM DISPUTA'; break;
    case 'REFUNDED':        return 'DEVOLVIDO'; break;
    case 'CANCELLED':       return 'CANCELADO'; break;
    default:                return ''; break;
    }    
  }
  
  function get_OrderStatus()
  {
    global $statusDescription;
    switch($statusDescription)
    {
      case "":
      case "Failed":        return -2; break;
      case "Denied":        return -1; break;
      case "Pending":       return -0; break;
      case "In-Progress":   return 1;  break;
      case "Completed":     return 2;  break;
      case "Processed":     return 3;  break;
      case "Refunded":      return 4;  break;
      default:              return 5;
    }
  }
  
  function get_CurrentCurrency()
  {    
    return 'BRL';
  }
  
  function selection()
  {
    return array('id' => $this->code, 'module' => $this->title);
  }

  function keys() 
  {
    return array(
        'MODULE_PAYMENT_PAGSEGURO_STATUS', 
        'MODULE_PAYMENT_PAGSEGURO_EMAIL', 
        'MODULE_PAYMENT_PAGSEGURO_TOKEN', 
        'MODULE_PAYMENT_PAGSEGURO_REDIRECTURL', 
        'MODULE_PAYMENT_PAGSEGURO_IDPREFIX', 
        'MODULE_PAYMENT_PAGSEGURO_PRODUCT',
        );
    
  }

  function update_status() { return false; }

  function javascript_validation() { return false; }

  function pre_confirmation_check() { return false; }

  function confirmation() { return false; }

  function after_process() { return false; }

  function output_error() { return false; }

  function before_process() { return false; }
  
  function install() { return false; }
  
  function remove() { return false; }  

}
