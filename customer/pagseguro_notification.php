<?php

include ("./lib/customer.defines.php");
include ("./lib/customer.module.access.php");
//include ("./lib/Form/Class.FormHandler.inc.php");
include ("./lib/epayment/includes/configure.php");
include ("./lib/epayment/classes/payment.php");
include ("./lib/epayment/classes/order.php");
include ("./lib/epayment/classes/currencies.php");
include ("./lib/epayment/includes/general.php");
//include ("./lib/epayment/includes/html_output.php");
include ("./lib/epayment/includes/loadconfiguration.php");

/* Código identificador da transação  */    
getpost_ifset (array('notificationCode'));

require_once('../common/lib/epayment/pagseguro/source/PagSeguroLibrary/PagSeguroLibrary.php');
$PagSeguro = PagSeguroLibrary::init();

$credentials = new PagSeguroAccountCredentials(
    MODULE_PAYMENT_PAGSEGURO_EMAIL,
    MODULE_PAYMENT_PAGSEGURO_TOKEN
); 
  
/*  
    Realizando uma consulta de transação a partir do código identificador  
    para obter o objeto Transaction 
*/   
try {
  $transaction = PagSeguroNotificationService::checkTransaction(  
      $credentials,  
      $notificationCode  // 'A08F36E8-F79B-40EF-9837-982E244C848C'
  );  
} catch (Exception $exc) {
  write_log(LOGFILE_EPAYMENT, basename(__FILE__).
    ' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: notification=$notificationCode".
    " FAILURE GETTING TRANSACTION: " . $exc->getTraceAsString());
   exit;
}

/**
 * Verificando o Status
 * https://pagseguro.uol.com.br/v2/guia-de-integracao/documentacao-da-biblioteca-pagseguro-em-php.html#TransactionStatus
 * 
 * COD  STRING           SIGNIFICADO
 * --------------------------------------------------------------------------------------
 * 1  WAITING_PAYMENT    Aguardando pagamento: o comprador iniciou a transação, 
 *                       mas até o momento o PagSeguro não recebeu nenhuma informação 
 *                       sobre o pagamento.  
 * 
 * 2  IN_ANALYSIS        Em análise: o comprador optou por pagar com um cartão 
 *                       de crédito e o PagSeguro está analisando o risco da transação.  
 * 
 * 3  PAID               Paga: a transação foi paga pelo comprador e o PagSeguro já 
 *                       recebeu uma confirmação da instituição financeira responsável 
 *                       pelo processamento.  
 * 
 * 4  AVAILABLE          Disponível: a transação foi paga e chegou ao final de seu prazo 
 *                       de liberação sem ter sido retornada e sem que haja nenhuma 
 *                       disputa aberta.  
 * 
 * 5  IN_DISPUTE         Em disputa: o comprador, dentro do prazo de liberação da transação, 
 *                       abriu uma disputa.  
 * 
 * 6  REFUNDED           Devolvida: o valor da transação foi devolvido para o comprador.  
 * 
 * 7  CANCELLED          Cancelada: a transação foi cancelada sem ter sido finalizada.  
 * 
 */

// Verificando se transação existe
if ($transaction)
{
  // TODO implementar prefixo na configuração do PAGSEGURO
  $transactionID = str_replace(MODULE_PAYMENT_PAGSEGURO_IDPREFIX, '', $transaction->getReference());
  
  $DBHandle_max  = DbConnect();
  $paymentTable = new Table();
  
  // Status - New 0 ; Proceed 1 ; In Process 2
  $QUERY = "SELECT id, cardid, amount, vat, paymentmethod, cc_owner, cc_number, cc_expires, creationdate, status, cvv, credit_card_type, currency, item_id, item_type " .
       " FROM cc_epayment_log " .
       " WHERE id = ".$transactionID." AND status = 2";
  
  $transaction_data = $paymentTable->SQLExec ($DBHandle_max, $QUERY);

  if(empty($transaction_data) || count($transaction_data) == 0) {
    write_log(LOGFILE_EPAYMENT, basename(__FILE__).
      ' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." ERROR INVALID TRANSACTION ID PROVIDED");
     exit;
  } else {
    write_log(LOGFILE_EPAYMENT, basename(__FILE__).
      ' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." EPAYMENT RESPONSE: TRANSACTIONID = ".$transactionID.
      " FROM ".$transaction_data[0][4]."; FOR CUSTOMER ID ".$transaction_data[0][1]."; OF AMOUNT ".$transaction_data[0][2]);
  }

  $paymentmethod = $pmodule = $transaction_data[0][4];
  $item_id       = $transaction_data[0][13];
  $item_type     = $transaction_data[0][14];

  $payment_modules = new payment($paymentmethod);
  
  $transaction_detail = serialize($_POST);

  $currencyObject 	= new currencies();
  $currencies_list 	= get_currencies();

  $currAmount = $transaction_data[0][2];
  $currCurrency = BASE_CURRENCY;

  if(empty($transaction_data[0]['vat']) || !is_numeric($transaction_data[0]['vat']))
    $VAT =0;
  else
    $VAT = $transaction_data[0]['vat'];

  write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: curr amount $currAmount $currCurrency ".BASE_CURRENCY);
  $amount_paid = convert_currency($currencies_list, $currAmount, $currCurrency, BASE_CURRENCY);
  $amount_without_vat = $amount_paid / (1+$VAT/100);
  
  //Update the Transaction Status to 2 ( In Process)
  $QUERY = "UPDATE cc_epayment_log SET status = 2 WHERE id = ".$transactionID;
  write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line: '.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: STATUS UPDATE TO 'In Process'");
  $paymentTable->SQLExec ($DBHandle_max, $QUERY);
  
  switch ($transaction->getStatus()->getTypeFromValue()) 
  {
    // Pagamento confirmado
    case 'PAID':
    case 'AVAILABLE':
      $final_status = 1;
      $statusDescription = "Completed";
      $orderStatus = $payment_modules->get_OrderStatus();
      break;

    // Pagamento em processamento
    case 'WAITING_PAYMENT':
    case 'IN_ANALYSIS':
    case 'IN_DISPUTE':
      $final_status = 2;
      $statusDescription = "In-Progress"; 
      //$statusDescription = "Pending";
      $orderStatus = $payment_modules->get_OrderStatus();
      break;

    // Pagamento em processamento
    case 'REFUNDED':
    case 'CANCELLED':
    default:
      $final_status = 2;
      //$orderStatus = "Denied";
      $statusDescription = "Failed";
      $orderStatus = $payment_modules->get_OrderStatus();
      break;
  }
} else {
  echo 'sem transação';
  exit;
}

$QUERY = "SELECT username, credit, lastname, firstname, address, city, state, country, zipcode, phone, email, fax, lastuse, activated, currency, useralias, uipass " .
     "FROM cc_card WHERE id = '".$transaction_data[0][1]."'";
$resmax = $DBHandle_max -> Execute($QUERY);
if ($resmax) {
  $numrow = $resmax -> RecordCount();
} else {
    write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." ERROR NO SUCH CUSTOMER EXISTS, CUSTOMER ID = ".$transaction_data[0][1]);
    exit(gettext("No Such Customer exists."));
}
$customer_info = $resmax -> fetchRow();
$nowDate = date("Y-m-d H:i:s");

$pagseguroStatus = pagseguro::getStatusPagSeguroDescriptionBR($transaction->getStatus()->getTypeFromValue());
write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." status $orderStatus ($pagseguroStatus)");


if(empty($item_type))
  $transaction_type='balance';
else
  $transaction_type = $item_type;

$Query = "INSERT INTO cc_payments ( customers_id, customers_name, customers_email_address, item_name, item_id, item_quantity, payment_method, cc_type, cc_owner, " .
      " cc_number, cc_expires, orders_status, last_modified, date_purchased, orders_date_finished, orders_amount, currency, currency_value) values (" .
      " '".$transaction_data[0][1]."', '".$customer_info[3]." ".$customer_info[2]."', '".$customer_info["email"]."', '$transaction_type', '".
      $customer_info[0]."', 1, '$pmodule', '".$_SESSION["p_cardtype"]."', '".$transaction_data[0][5]."', '".$transaction_data[0][6]."', '".
      $transaction_data[0][7]."',  $orderStatus, '".$nowDate."', '".$nowDate."', '".$nowDate."',  ".$amount_paid.",  '".$currCurrency."', '".
      $currencyObject->get_value($currCurrency)."' )";
$result = $DBHandle_max -> Execute($Query);

//echo '<pre>'; print_r($transaction_data); exit;
//echo $paymentmethod; exit;


// UPDATE THE CARD CREDIT
$id = 0;
if ($customer_info[0] > 0 && $orderStatus == 2) {
    /* CHECK IF THE CARDNUMBER IS ON THE DATABASE */
    $instance_table_card = new Table("cc_card", "username, id");
    $FG_TABLE_CLAUSE_card = " username='".$customer_info[0]."'";
    $list_tariff_card = $instance_table_card -> Get_list ($DBHandle, $FG_TABLE_CLAUSE_card, null, null, null, null, null, null);
    if ($customer_info[0] == $list_tariff_card[0][0]) {
        $id = $list_tariff_card[0][1];
    }
  write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." CARD FOUND IN DB ($id)");
} else {
    write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." ERROR CUSTOMER INFO OR ORDERSTATUS ($orderStatus)\n".print_r($_POST, true)."\n". $Query . "\n");
}

if ($id > 0 ) 
{
  if (strcasecmp("invoice",$item_type)!=0) 
  {
    $addcredit = $transaction_data[0][2]; 
    $instance_table = new Table("cc_card", "username, id");
    $param_update .= " credit = credit+'".$amount_without_vat."'";
    $FG_EDITION_CLAUSE = " id='$id'";
    $instance_table -> Update_table ($DBHandle, $param_update, $FG_EDITION_CLAUSE, $func_table = null);
    write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." Update_table cc_card : $param_update - CLAUSE : $FG_EDITION_CLAUSE");
    
    $table_transaction = new Table();
    $result_agent = $table_transaction -> SQLExec($DBHandle,"SELECT cc_card_group.id_agent FROM cc_card LEFT JOIN cc_card_group ON cc_card_group.id = cc_card.id_group WHERE cc_card.id = $id");
    if (is_array($result_agent) && !is_null($result_agent[0]['id_agent']) && $result_agent[0]['id_agent']>0 ) 
    {
      $id_agent =  $result_agent[0]['id_agent'];
      $id_agent_insert = "'$id_agent'";
    } else {
      $id_agent = null;
      $id_agent_insert = "NULL";
    }
    
    $field_insert = "date, credit, card_id, description, agent_id";
    $value_insert = "'$nowDate', '".$amount_without_vat."', '$id', '".$transaction_data[0][4]."',$id_agent_insert";
    $instance_sub_table = new Table("cc_logrefill", $field_insert);
    $id_logrefill = $instance_sub_table -> Add_table ($DBHandle, $value_insert, null, null, 'id');
    write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." Add_table cc_logrefill : $field_insert - VALUES $value_insert");
    
    $field_insert = "date, payment, card_id, id_logrefill, description, agent_id";
    $value_insert = "'$nowDate', '".$amount_paid."', '$id', '$id_logrefill', '".$transaction_data[0][4]."',$id_agent_insert ";
    $instance_sub_table = new Table("cc_logpayment", $field_insert);
    $id_payment = $instance_sub_table -> Add_table ($DBHandle, $value_insert, null, null,"id");
    write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." Add_table cc_logpayment : $field_insert - VALUES $value_insert");
    
    //ADD an INVOICE
    $reference = generate_invoice_reference();
    $field_insert = "date, id_card, title ,reference, description,status,paid_status";
    $date = $nowDate;
    $card_id = $id;
    $title = gettext("CUSTOMER REFILL");
    $description = gettext("Invoice for refill");
    $value_insert = " '$date' , '$card_id', '$title','$reference','$description',1,1 ";
    $instance_table = new Table("cc_invoice", $field_insert);
    $id_invoice = $instance_table -> Add_table ($DBHandle, $value_insert, null, null,"id");
    
    //load vat of this card
    if (!empty($id_invoice)&& is_numeric($id_invoice)) 
    {
      $amount = $amount_without_vat;
      $description = gettext("Refill ONLINE")." : ".$transaction_data[0][4];
      $field_insert = "date, id_invoice ,price,vat, description";
      $instance_table = new Table("cc_invoice_item", $field_insert);
      $value_insert = " '$date' , '$id_invoice', '$amount','$VAT','$description' ";
      $instance_table -> Add_table ($DBHandle, $value_insert, null, null,"id");
    }
      //link payment to this invoice
    $table_payment_invoice = new Table("cc_invoice_payment", "*");
    $fields = " id_invoice , id_payment";
    $values = " $id_invoice, $id_payment  ";
    $table_payment_invoice->Add_table($DBHandle, $values, $fields);
    //END INVOICE

    // Agent commision
    // test if this card have a agent    
    if (!empty($id_agent)) 
    {
      //test if the agent exist and get its commission
      $agent_table = new Table("cc_agent", "commission");
      $agent_clause = "id = ".$id_agent;
      $result_agent= $agent_table -> Get_list($DBHandle,$agent_clause);
      
      if(is_array($result_agent) && is_numeric($result_agent[0]['commission']) && $result_agent[0]['commission']>0) 
      {
        $field_insert = "id_payment, id_card, amount,description,id_agent,commission_percent,commission_type";
        $commission = ceil(($amount_without_vat * ($result_agent[0]['commission'])/100)*100)/100;
        $commission_percent = $result_agent[0]['commission'];

        $description_commission = gettext("AUTOMATICALY GENERATED COMMISSION!");
        $description_commission.= "\nID CARD : ".$id;
        $description_commission.= "\nID PAYMENT : ".$id_payment;
        $description_commission.= "\nPAYMENT AMOUNT: ".$amount_without_vat;
        $description_commission.= "\nCOMMISSION APPLIED: ".$commission_percent;

        $value_insert = "'".$id_payment."', '$id', '$commission','$description_commission','$id_agent','$commission_percent','0'";
        $commission_table = new Table("cc_agent_commission", $field_insert);
        $id_commission = $commission_table -> Add_table ($DBHandle, $value_insert, null, null,"id");
        write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." Add_table cc_agent_commission : $field_insert - VALUES $value_insert");

        $table_agent = new Table('cc_agent');
        $param_update_agent = "com_balance = com_balance + '".$commission."'";
        $clause_update_agent = " id='".$id_agent."'";
        $table_agent -> Update_table ($DBHandle, $param_update_agent, $clause_update_agent, $func_table = null);
        write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." Update_table cc_agent : $param_update_agent - CLAUSE : $clause_update_agent");
      }
    }
  } else {
    if($item_id>0) 
    {
      $invoice_table = new Table('cc_invoice','reference');
      $invoice_clause = "id = ".$item_id;
      $result_invoice = $invoice_table->Get_list($DBHandle,$invoice_clause);
      
      if (is_array($result_invoice) && sizeof($result_invoice)==1) 
      {
        $reference =$result_invoice[0][0];
        
        $field_insert = "date, payment, card_id, description";
        $value_insert = "'$nowDate', '".$amount_paid."', '$id', '(".$transaction_data[0][4].") ".gettext('Invoice Payment Ref: ')."$reference '";
        $instance_sub_table = new Table("cc_logpayment", $field_insert);
        $id_payment = $instance_sub_table -> Add_table ($DBHandle, $value_insert, null, null,"id");
        write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." Add_table cc_logpayment : $field_insert - VALUES $value_insert");

        //update invoice to paid
        $invoice = new Invoice($item_id);
        $invoice -> addPayment($id_payment);
        $invoice -> changeStatus(1);
        $items = $invoice -> loadItems();
        foreach ($items as $item) 
        {
          if ($item -> getExtType() == 'DID') 
          {
            $QUERY = "UPDATE cc_did_use set month_payed = month_payed+1 , reminded = 0 WHERE id_did = '" . $item -> getExtId() .
                 "' AND activated = 1 AND ( releasedate IS NULL OR releasedate < '1984-01-01 00:00:00') ";
            $instance_table->SQLExec($DBHandle, $QUERY, 0);
          }
          if ($item -> getExtType() == 'SUBSCR') {
            //Load subscription
            write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION:  Type SUBSCR");
            $table_subsc = new Table('cc_card_subscription','paid_status');
            $subscr_clause = "id = ".$item -> getExtId();
            $result_subscr = $table_subsc -> Get_list($DBHandle,$subscr_clause);
            if(is_array($result_subscr))
            {
              $subscription = $result_subscr[0];
              write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: cc_card_subscription paid_status : ".$subscription['paid_status']);
              if($subscription['paid_status']==3){
                $billdaybefor_anniversery = $A2B->config['global']['subscription_bill_days_before_anniversary'];
                $unix_startdate = time();
                $startdate = date("Y-m-d",$unix_startdate);
                $day_startdate = date("j",$unix_startdate);
                $month_startdate = date("m",$unix_startdate);
                $year_startdate= date("Y",$unix_startdate);
                $lastday_of_startdate_month = lastDayOfMonth($month_startdate,$year_startdate,"j");

                $next_bill_date = strtotime("01-$month_startdate-$year_startdate + 1 month");
                $lastday_of_next_month= lastDayOfMonth(date("m",$next_bill_date),date("Y",$next_bill_date),"j");

                if ($day_startdate > $lastday_of_next_month) 
                {
                  $next_limite_pay_date = date ("$lastday_of_next_month-m-Y" ,$next_bill_date);
                } else {
                  $next_limite_pay_date = date ("$day_startdate-m-Y" ,$next_bill_date);
                }

                $next_bill_date = date("Y-m-d",strtotime("$next_limite_pay_date - $billdaybefor_anniversery day")) ;
                $QUERY = "UPDATE cc_card SET status=1 WHERE id=$id";
                $result = $instance_table->SQLExec($DBHandle, $QUERY, 0);
                write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: QUERY : $QUERY - RESULT : $result");
                                
                $QUERY = "UPDATE cc_card_subscription SET paid_status = 2, startdate = '$startdate' ,limit_pay_date = '$next_limite_pay_date',   next_billing_date ='$next_bill_date' WHERE id=" . $item -> getExtId();
                write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: QUERY : $QUERY");
                $instance_table->SQLExec($DBHandle, $QUERY, 0);
              }else{
                $QUERY = "UPDATE cc_card SET status=1 WHERE id=$id";
                $result = $instance_table->SQLExec($DBHandle, $QUERY, 0);
                write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: QUERY : $QUERY - RESULT : $result");

                $QUERY = "UPDATE cc_card_subscription SET paid_status = 2 WHERE id=". $item -> getExtId();
                write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: QUERY : $QUERY");
                $instance_table->SQLExec($DBHandle, $QUERY, 0);
              }
            }
          }
        }
      }
    }
  }
}

$QUERY = "UPDATE cc_epayment_log SET status = $final_status, transaction_detail ='".addslashes($transaction_detail)."' WHERE id = ".$transactionID;
write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: QUERY = $QUERY");
$paymentTable->SQLExec ($DBHandle_max, $QUERY);

write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." EPAYMENT ORDER STATUS  = ".$statusDescription);

/** Citydata BEGIN  */
require_once dirname(__FILE__) . '/../citydata/citydata_functions.php'; 
if ($final_status === 1 && citydata_check_customer_tariff($customer_info)) {
    citydata_set_expiration_date($customer_info);
}
/** Citydata END */

// CHECK IF THE EMAIL ADDRESS IS CORRECT
if (preg_match("/^[a-z]+[a-z0-9_-]*(([.]{1})|([a-z0-9_-]*))[a-z0-9_-]+[@]{1}[a-z0-9_-]+[.](([a-z]{2,3})|([a-z]{3}[.]{1}[a-z]{2}))$/i", $customer_info["email"])) {
  // FIND THE TEMPLATE APPROPRIATE
  
    try {
        $mail = new Mail(Mail::$TYPE_PAYMENT,$id);
        write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: SENDING EMAIL TO CUSTOMER ".$customer_info["email"]);
        $mail->replaceInEmail(Mail::$ITEM_AMOUNT_KEY,$amount_paid);
        $mail->replaceInEmail(Mail::$ITEM_ID_KEY,$id_logrefill);
        $mail->replaceInEmail(Mail::$ITEM_NAME_KEY,'balance');
        $mail->replaceInEmail(Mail::$PAYMENT_METHOD_KEY,$pmodule);
        $mail->replaceInEmail(Mail::$PAYMENT_STATUS_KEY,$statusDescription);
        $mail->send($customer_info["email"]);
        
        write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: SENDING EMAIL TO CUSTOMER ".$customer_info["email"]);
        write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"."- MAILTO:".$customer_info["email"]."-Sub=".$mail->getTitle()." , mtext=".$mail->getMessage());
        
        // Add Post information / useful to track down payment transaction without having to log
        $mail->AddToMessage("\n\n\n\n"."-POST Var \n".print_r($_POST, true));
        $mail->setTitle("COPY FOR ADMIN : ".$mail->getTitle());
        $mail->send(ADMIN_EMAIL);
        
    } catch (A2bMailException $e) {
        write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." ERROR NO EMAIL TEMPLATE FOUND");
    }
  
} else {
  write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." Customer : no email info !!!");
}

write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." EPAYMENT ORDER STATUS ID = ".$orderStatus." ".$statusDescription);
write_log(LOGFILE_EPAYMENT, basename(__FILE__).' line:'.__LINE__." EPAYMENT PAGSEGURO NOTIFICATION: transactionID=$transactionID"." ----EPAYMENT TRANSACTION END----");

exit;