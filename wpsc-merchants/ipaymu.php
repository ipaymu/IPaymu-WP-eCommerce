<?php
$nzshpcrt_gateways[$num]['name'] = __( 'Ipaymu', 'wpsc' );
$nzshpcrt_gateways[$num]['internalname'] = 'ipaymu';
$nzshpcrt_gateways[$num]['function'] = 'gateway_ipaymu';
$nzshpcrt_gateways[$num]['form'] = "form_ipaymu";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_ipaymu";
$nzshpcrt_gateways[$num]['payment_type'] = "credit_card";
$nzshpcrt_gateways[$num]['display_name'] = __( 'Ipaymu', 'wpsc' );
$nzshpcrt_gateways[$num]['image'] = WPSC_URL . '/images/ipaymu_badge.png';

function gateway_ipaymu($separator, $sessionid)
{
	global $wpdb;
	$purchase_log_sql = $wpdb->prepare( "SELECT * FROM `".WPSC_TABLE_PURCHASE_LOGS."` WHERE `sessionid`= %s LIMIT 1", $sessionid );
	$purchase_log = $wpdb->get_results($purchase_log_sql,ARRAY_A) ;

	$cart_sql = "SELECT * FROM `".WPSC_TABLE_CART_CONTENTS."` WHERE `purchaseid`='".$purchase_log[0]['id']."'";
	$cart = $wpdb->get_results($cart_sql,ARRAY_A) ;

	$curr=new CURRENCYCONVERTER();
	$decimal_places = 2;
	$total_price = 0;

	$i = 1;

	$all_donations = true;
	$all_no_shipping = true;

	foreach($cart as $item)
	{
		$product_data = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `" . $wpdb->posts . "` WHERE `id`= %d LIMIT 1", $item['prodid'] ), ARRAY_A );
		$product_data = $product_data[0];
		$variation_count = count($product_variations);

		//Does this even still work in 3.8? We're not using this table.
		$variation_sql = $wpdb->prepare( "SELECT * FROM `".WPSC_TABLE_CART_ITEM_VARIATIONS."` WHERE `cart_id` = %d", $item['id'] );
		$variation_data = $wpdb->get_results( $variation_sql, ARRAY_A );
		$variation_count = count($variation_data);

		if($variation_count >= 1)
      	{
      		$variation_list = " (";
      		$j = 0;

      		foreach($variation_data as $variation)
        	{
        		if($j > 0)
          		{
          			$variation_list .= ", ";
          		}
        		$value_id = $variation['venue_id'];
        		$value_data = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `".WPSC_TABLE_VARIATION_VALUES."` WHERE `id`= %d LIMIT 1", $value_id ), ARRAY_A);
        		$variation_list .= $value_data[0]['name'];
        		$j++;
        	}
      		$variation_list .= ")";
      	}
      	else
        {
        	$variation_list = '';
        }

    	$local_currency_productprice = $item['price'];

			$local_currency_shipping = $item['pnp'];


			$chronopay_currency_productprice = $local_currency_productprice;
			$chronopay_currency_shipping = $local_currency_shipping;

    	$data['item_name_'.$i] = $product_data['name'].$variation_list;
    	$data['amount_'.$i] = number_format(sprintf("%01.2f", $chronopay_currency_productprice),$decimal_places,'.','');
    	$data['quantity_'.$i] = $item['quantity'];
    	$data['item_number_'.$i] = $product_data['id'];

		if($item['donation'] !=1)
      	{
      		$all_donations = false;
      		$data['shipping_'.$i] = number_format($chronopay_currency_shipping,$decimal_places,'.','');
      		$data['shipping2_'.$i] = number_format($chronopay_currency_shipping,$decimal_places,'.','');
      	}
      	else
      	{
      		$data['shipping_'.$i] = number_format(0,$decimal_places,'.','');
      		$data['shipping2_'.$i] = number_format(0,$decimal_places,'.','');
      	}

    	if($product_data['no_shipping'] != 1) {
      		$all_no_shipping = false;
      	}


		$total_price = $total_price + ($data['amount_'.$i] * $data['quantity_'.$i]);

		if( $all_no_shipping != false )
			$total_price = $total_price + $data['shipping_'.$i] + $data['shipping2_'.$i];

    	$i++;
	}
  	$base_shipping = $purchase_log[0]['base_shipping'];
  	if(($base_shipping > 0) && ($all_donations == false) && ($all_no_shipping == false))
    {
		$data['handling_cart'] = number_format($base_shipping,$decimal_places,'.','');
		$total_price += number_format($base_shipping,$decimal_places,'.','');
    }

	$data['product_price'] = $total_price;

	$api = get_option('key');

	$paypalemail = get_option('paypal_email');

	$uniquecode = get_option('unique_code');
	//$sessionid = $this->cart_data['session_id'];

	$url = 'https://my.ipaymu.com/payment.htm';

	// Prepare Parameters
	$params = array(
			'key'      => ''.$api.'', // API Key Merchant / Penjual
			'action'   => 'payment',
			'product'  => 'Order #'.$purchase_log[0]['id'].'',
			'price'    => ''.$total_price.'', // Total Harga
			'quantity' => 1,
			'comments' => 'Transaksi Pembelian', // Optional           
			'ureturn'  => ''.get_option('transact_url') ."/sessionid=$sessionid".'',
			'unotify'  => 'http://ldomain.com/notify.php',
			'ucancel'  => 'http://domain.com/cancel.php',

			/* Parameter untuk pembayaran lain menggunakan PayPal 
             * ----------------------------------------------- */
            'invoice_number' => uniqid($uniquecode), // Optional
            'paypal_email'   => $paypalemail,
            'paypal_price'   => 1, // Total harga dalam kurs USD
            /* ----------------------------------------------- */

			'format'   => 'json' // Format: xml / json. Default: xml 
	);
			
	$params_string = http_build_query($params);
			
	//open connection_aborted(oci_internal_debug(onoff))
	$ch = curl_init();
	 
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, count($params));
	curl_setopt($ch, CURLOPT_POSTFIELDS, $params_string);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	 
	//execute post
	$request = curl_exec($ch);
	 
	if ( $request === false ) {
	    echo 'Curl Error: ' . curl_error($ch);
	} else {
	     
	    $result = json_decode($request, true);
	 
	    if( isset($result['url']) )
	        header('location: '. $result['url']);
	    else {
	        echo "Request Error ". $result['Status'] .": ". $result['Keterangan'];
	    }
	}
			
	//close connection
	curl_close($ch);

  	exit();
}

function nzshpcrt_ipaymu_results()
{
	// Function used to translate the Ipaymu returned cs1=sessionid POST variable into the recognised GET variable for the transaction results page.
	if(isset($_POST['cs1']) && ($_POST['cs1'] !='') && ($_GET['sessionid'] == ''))
	{
		$_GET['sessionid'] = $_POST['cs1'];
	}
}

function submit_ipaymu()
{
	if(isset($_POST['ipaymu_username']))
		{
			update_option('ipaymu_username', $_POST['ipaymu_username']);
		}
		
		if(isset($_POST['toko']))
		{
			update_option('toko', $_POST['toko']);
		}
		
		if(isset($_POST['key']))
		{
			update_option('key', $_POST['key']);
		}
		
		if(isset($_POST['paypalemail']))
		{
			update_option('paypal_email', $_POST['paypalemail']);
		}
		
		if(isset($_POST['uniquecode']))
		{
			update_option('unique_code', $_POST['uniquecode']);
		}
		
		if (!isset($_POST['ipaymu_form'])) $_POST['ipaymu_form'] = array();
		foreach((array)$_POST['ipaymu_form'] as $form => $value)
		{
			update_option(('ipaymu_form_'.$form), $value);
		}
		return true;
}

function form_ipaymu()
{
	$key = ( get_option('key')=='' ? '' : get_option('key') );

	$output = "
		<tr>
			<td style='width:120px'>Username / Email</td>
			<td><input type='text' size='40' value='".get_option('ipaymu_username')."' name='ipaymu_username' /><input type='hidden' value='".get_bloginfo('name')."' name='toko' /><br />
			<small>Username atau Email akun iPayMu Anda.</small></td>
		</tr>
		<tr>
			<td>API Key</td>
			<td><input type='text' size='40' value='".get_option('key')."' name='key' /><br />
			<small>Dapatkan API Key <a href='https://ipaymu.com/login/members/profile.htm' target='_blank'>di sini</a></small></td>
		</tr>
		<tr>
			<td>Paypal Email</td>
			<td><input type='text' size='40' value='".get_option('paypal_email')."' name='paypalemail' /><br />
			<small>User Paypal Email</small></td>
		</tr>
		<tr>
			<td>Order ID</td>
			<td><input type='text' size='40' value='".get_option('unique_code')."' name='uniquecode' /><br />
			<small>Ex: INV-</small></td>
		</tr>
		<tr>
			<td colspan='2'><hr style='border:none; border-top: 1px solid #E9E9E9; border-bottom:1px solid #fff; margin:4px 0;' /></td>
		</tr>
		<tr><td colspan='2'>Belum punya akun iPayMu? <a class='button' href='https://ipaymu.com/login/members/signup.htm' target='_blank'>Daftar</a> gratis sekarang!</td></tr>";

	return $output;
}


//add_action('init', 'nzshpcrt_ipaymu_callback');
//add_action('init', 'nzshpcrt_ipaymu_results');

?>