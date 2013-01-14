<?php

include ("./lib/customer.defines.php");
include ("./lib/customer.module.access.php");
//include ("./lib/Form/Class.FormHandler.inc.php");
include ("./lib/epayment/includes/configure.php");
include ("./lib/epayment/classes/payment.php");
//include ("./lib/epayment/classes/order.php");
//include ("./lib/epayment/classes/currencies.php");
//include ("./lib/epayment/includes/general.php");
//include ("./lib/epayment/includes/html_output.php");
include ("./lib/epayment/includes/loadconfiguration.php");

if (! has_rights (ACX_ACCESS)) {
	Header ("HTTP/1.0 401 Unauthorized");
	Header ("Location: PP_error.php?c=accessdenied");
	die();
}

require_once('../common/lib/epayment/methods/pagseguro.php');

$inst_table = new Table();

$QUERY = "SELECT username, credit, lastname, firstname, address, city, state, country, zipcode, phone, email, fax, lastuse, activated, status, " .
"freetimetocall, label, packagetype, billingtype, startday, id_cc_package_offer, cc_card.id, currency,cc_card.useralias,UNIX_TIMESTAMP(cc_card.creationdate) creationdate  FROM cc_card " .
"LEFT JOIN cc_tariffgroup ON cc_tariffgroup.id=cc_card.tariff LEFT JOIN cc_package_offer ON cc_package_offer.id=cc_tariffgroup.id_cc_package_offer " .
"LEFT JOIN cc_card_group ON cc_card_group.id=cc_card.id_group WHERE username = '" . $_SESSION["pr_login"] .
"' AND uipass = '" . $_SESSION["pr_password"] . "'";

$DBHandle = DbConnect();

$customer_res = $inst_table -> SQLExec($DBHandle, $QUERY);

if (!$customer_res || !is_array($customer_res)) {
	echo gettext("Error loading your account information!");
	exit ();
}

$customer_info = $customer_res[0];

require_once('../common/lib/epayment/pagseguro/source/PagSeguroLibrary/PagSeguroLibrary.php');
$PagSeguro = PagSeguroLibrary::init();

try {
  $paymentRequest = new PagSeguroPaymentRequest();
  $paymentRequest->setCurrency('BRL'); // Única disponível
  $paymentRequest->setSenderName(utf8_decode($customer_info['firstname'] . ' ' .  $customer_info['lastname']));
  $paymentRequest->setSenderPhone(
          substr($customer_info['phone'], 0, 2), 
          substr($customer_info['phone'], 2,8)
          );
  //$paymentRequest->setSenderEmail($customer_info['email']);
  $paymentRequest->setShippingAddress(
      $customer_info['zipcode'],
      utf8_decode($customer_info['address']),
      '', // número
      '', // complemento
      '', // bairro
      utf8_decode($customer_info['city']),
      utf8_decode($customer_info['state']),
      'BRA'
  );
  $paymentRequest->setShippingType(3); //NOT_SPECIFIED	Não especificar tipo de frete
  $paymentRequest->addItem('0001', MODULE_PAYMENT_PAGSEGURO_PRODUCT, 1, $_POST['VlrTotal']);
  $paymentRequest->setReference(MODULE_PAYMENT_PAGSEGURO_IDPREFIX . $_POST['identPedido']);
  $paymentRequest->setRedirectURL(MODULE_PAYMENT_PAGSEGURO_REDIRECTURL);

  $credentials = new PagSeguroAccountCredentials(
      MODULE_PAYMENT_PAGSEGURO_EMAIL,
      MODULE_PAYMENT_PAGSEGURO_TOKEN
  );

  $url = $paymentRequest->register($credentials);
  header('Location:' . $url);
} catch (Exception $exc) {
  write_log(LOGFILE_EPAYMENT, basename(__FILE__).
    ' line:'.__LINE__." EPAYMENT PAGSEGURO: transactionID={$_POST['identPedido']}".
    " FAILURE STARTING PAYMENT REQUEST: \n" . $exc->getTraceAsString()
    . "-  TOKEN: " . MODULE_PAYMENT_PAGSEGURO_TOKEN . " -  EMAIL: " . MODULE_PAYMENT_PAGSEGURO_EMAIL
    );
   header('Location: checkout_success.php?errcode=-2');
}

  