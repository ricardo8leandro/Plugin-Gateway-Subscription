<?php 

namespace Hero99\Getnet;

use \stdClass;
use \WC_Order;

defined('ABSPATH') || exit('No direct script access allowed');

class API
{
   private const URLS = [
      'sandbox'      => 'https://api-homologacao.getnet.com.br/',
      'production'   => 'https://api.getnet.com.br/'
   ];

   public function __construct(string $seller_ID = '', string $client_ID = '', string $clientSecret = '', bool $isSandbox = false)
   {
      $this->seller_ID     = $seller_ID;
      $this->client_ID     = $client_ID;
      $this->clientSecret  = $clientSecret;
      $this->env           = $isSandbox ? 'sandbox' : 'production';

   }

   private function getUrl(): string
   {
      return self::URLS[ $this->env ];
   }

   public function isAvailable(): bool
   {
      return $this->seller_ID !== '' &&  $this->client_ID !== '' &&  $this->clientSecret !== '';
   }

   public function processPayment(WC_Order $order, int $installments, array $cc): stdClass
   {
      $isPerson   = (int) $order->get_meta('_billing_persontype') === 1;
      $doc        = $isPerson ? Utils::OnlyDigits($order->get_meta('_billing_cpf')) : Utils::OnlyDigits($order->get_meta('_billing_cnpj'));

      $session_ID = $doc . $order->get_id() . $this->client_ID;
      $customer_id = (string) 'customer_'.$order->get_customer_id();
      $order_id = $this->getOrderData($order);
      $order_id = $order_id->order_id;
      setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'pt_BR.utf-8', 'portuguese');
      date_default_timezone_set('America/Sao_Paulo');
      $date = strftime('%Y-%m-%d', strtotime('today'));
      

      //Cadastra Assinatura e efetiva Pagamento
      $data  =  [
         'seller_id' => $this->seller_ID,
         'customer_id' => $customer_id,
         'plan_id' => $this->planId(),
         'order_id'     => (int) $order_id,
         'subscription' => [
            'payment_type' => [
               'credit' => [
                  'transaction_type' => 'FULL',
                  'number_installments' => 1,
                  'soft_descriptor' => 'PLANO*ASSIN*OUNCES',
                  'customer'  => $this->getCustomerData($order),
                  'card' => $this->getCreditData($installments, $cc)
                  ]
               ]
            ],
         'shippings' => $this->getShippingData($order),
         'device'    => $this->getDeviceData($session_ID),
         'installment_start_date' => $date
      ];

      $res = $this->fetchGetnetData('v1/subscriptions', $data);

      return $this->traitPaymentResult( $res );
   }

   public function refundPayment(string $paymentId): bool
   {
      $endpoint = 'v1/payments/credit/' . $paymentId . '/cancel';
      $res = $this->fetchGetnetData($endpoint);
      return isset($res['status_code']) ? false : true;
   }

   public function getCardToken(string $cardNumber)  
   {
      $data = (object) [
         'card_number' => Utils::onlyDigits($cardNumber)
      ];

      $res = $this->fetchGetnetData('v1/tokens/card', $data);

      return isset($res['number_token']) ? $res['number_token'] : null;
   }

   public function planId()
   {
   
      $product_id = '';
      foreach( WC()->cart->get_cart() as $cart_item ){
         $product_id = $cart_item['product_id'];
         break;
      }
      $plan_id = get_post_meta( $product_id, '_plan_id_get_net', true );
      return $plan_id;
   
   }


     public function validateCard($token, $month, $year, $cvv, $name): bool
   {
      $data = (object) [
         'number_token'      => (string) $token,
         'expiration_month'  => (string) $month,
         'expiration_year'   => (string) $year,
         'security_code'     => (string) $cvv,
         'cardholder_name'   => (string) $name,
      ];
      $res = $this->fetchGetnetData('v1/cards/verification', $data);

      if (isset($res['status_code'])) {
         WC_Hero99::log('GETNET: não foi possível validar o cartão devido a ' . $res['message']);
         WC_Hero99::log(print_r($res, true));

         wc_add_notice('GETNET: Cartão inválido', 'error');
         return false;

      }

      return $res['status'] === 'VERIFIED';
   }

   private function traitPaymentResult(array $data): stdClass
   {
      Utils::log($data);

      $res = (object) [
         'success' => false,
         'message' => '',
         'ID'      => 0,
         'date'    => date('U')
      ];

      if(isset($data['status'])):
         $res->success  = true;
         $res->order_id = $data['order_id'];
         $res->ID       = $data['subscription']['subscription_id'];
         update_post_meta( $res->order_id, 'subscription_id', $res->ID );    
         

         
      elseif(isset($data['message'])):
         $res->message  = $data['message'];
         $res->message .= ' ' . $data['details'][0]['description'] ?? '';
         $res->message .= $data['name'];
         $res->message .= $data['status_code'];
         wc_add_notice( 'Erro ao processar o pagamento: ' .$res->message , 'error' );
  
      endif;

      
      return $res;
   }

   private function getOrderData(WC_Order $order): stdClass
   {
      return (object) [
         'order_id' => (string) $order->get_id()
      ];
   }

   private function getCustomerData(WC_Order $order): stdClass
   {
       
      $isPerson   = (int) $order->get_meta('_billing_persontype') === 1;
      $doc        = $isPerson ? Utils::OnlyDigits($order->get_meta('_billing_cpf')) : Utils::OnlyDigits($order->get_meta('_billing_cnpj'));

      return (object) [
         'billing_address'    => (object) [
            'street'       => (string) $order->get_billing_address_1(),
            'number'       => (string) $order->get_meta('_billing_number'),
            'complement'   => (string) $order->get_billing_address_2(),
            'district'     => (string) $order->get_meta('_billing_neighborhood'),
            'city'         => (string) $order->get_billing_city(),
            'state'        => (string) $order->get_billing_state(),
            'country'      => (string) $order->get_billing_country(),
            'postal_code'  => (string) Utils::OnlyDigits($order->get_billing_postcode()),
         ]
         ];
   }


   private function getDeviceData($session_ID): stdClass
   {
      $this->getDeviceFingerPrint( $session_ID );

      return (object) [
         'ip_address'   => (string) $_SERVER['REMOTE_ADDR'] ?? '',
         'device_id'    => (string) $session_ID,
      ];
   }

   private function getShippingData(WC_Order $order): array
   {
      return [
         (object) [
            'first_name'      => (string) $order->get_shipping_first_name(),
            'name'            => (string) $order->get_formatted_shipping_full_name(),
            'email'           => (string) $order->get_billing_email(), 
            'phone_number'    => (string) Utils::OnlyDigits($order->get_billing_phone()), 
            'shipping_amount' => (int) $order->get_shipping_total() * 100,
            'address'         => (object) [
               'street'          => (string) $order->get_shipping_address_1(),
               'number'          => (string) $order->get_meta('_shipping_number'),
               'complement'      => (string) $order->get_shipping_address_2(),
               'district'        => (string) $order->get_meta('_shipping_neighborhood'),
               'city'            => (string) $order->get_shipping_city(),
               'state'           => (string) $order->get_shipping_state(),
               'country'         => (string) $order->get_shipping_country(),
               'postal_code'     => (string) Utils::OnlyDigits($order->get_shipping_postcode()),
            ]
         ]
      ];
   }

   private function getCreditData(int $installments, array $cc): stdClass
   {
      return (object) [
         
            'number_token'          => (string) $cc['token'],
            'cardholder_name'       => (string) $cc['name'],
            'security_code'         => (string) $cc['cvc'],
            'expiration_month'      => (string) $cc['expMonth'],
            'expiration_year'       => (string) $cc['expYear'],
            
      ];
   }


   private function fetchGetnetData(string $endpoint, $data = null): array
   {
      $url = $this->getUrl() . $endpoint;
      $headers = $this->getHeaders();

      if(empty($headers)) return [];

      $req = wp_remote_post($url, [
         'body'    => ($data) ? wp_json_encode($data) : '',
         'headers' => $headers,
      ]);

      $rawRes = wp_remote_retrieve_body($req);
      $res = json_decode($rawRes, true);
      return $res;
   }

   private function getHeaders(): array
   {
      $token = $this->getAuthToken();

      if(!$token) return [];

      return [
         'Content-Type'  => 'application/json; charset=utf-8',
         'Authorization' => 'Bearer ' . $token,
         'seller_id'     => $this->seller_ID,
      ];
   }

   private function getAuthToken(): string
   {
      $url = $this->getUrl() . 'auth/oauth/v2/token';
      

     

      $req = wp_remote_post($url, [
         'body'    => 'scope=oob&grant_type=client_credentials',
         'headers' => [
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode($this->client_ID . ':' . $this->clientSecret),
            'seller_id'     => $this->seller_ID,
         ],
      ]);

      

      $rawRes = wp_remote_retrieve_body($req);
      $res = json_decode($rawRes, true);


      if (isset($res['error'])) {
         Utils::log('GETNET: não foi possível gerar o token de autorização devido a ' . $res['error_description']);
         Utils::log(print_r($res, true));

         if(current_user_can('manage_options')):
            wc_add_notice('Getnet: Não foi possível obter o código de autenticação. Por favor, verifique as credenciais informadas.', 'error');
         else:
            wc_add_notice('Getnet: Não foi possível obter o código de autenticação. Por favor, contate o administrador do site.', 'error');
         endif;
         return '';
      }
      
      return $res['access_token'];
   }

   private function getDeviceFingerPrint(string $session_ID)
   {
      $url = add_query_arg(
         [
            'org_id'       => $this->env === 'sandbox' ? '1snn5n9w' : 'k8vif92e',
            'session_id'   => $session_ID,    
         ],
         'https://h.online-metrix.net/fp/tags.js'
      );

      wp_remote_get($url);
   }

}
