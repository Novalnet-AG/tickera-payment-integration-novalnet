(function($){
  $(document).ready(function(){
	if($('.active-gateway .tc_active_gateways').val() == "novalnet_payment" ){
		$('#novalnet_payment').css('display','block');
	}  
    $("#tc-gateways-form").submit(function(e){		
		if($('.active-gateway .tc_active_gateways').val() == "novalnet_payment" ){		
			 validate_merchant(e);
		}
    });
  });
})(jQuery);

function validate_merchant(e){
	var merchant_id = (document.getElementById('tc[gateways][novalnet_payment][vendor_id]').value).replace(/^\s+|\s+$/g, '');
	var auth_code = (document.getElementById('tc[gateways][novalnet_payment][auth_code]').value).replace(/^\s+|\s+$/g, '');
	var product_id = (document.getElementById('tc[gateways][novalnet_payment][product_id]').value).replace(/^\s+|\s+$/g, '');
	var tariff = (document.getElementById('tc[gateways][novalnet_payment][tariff]').value).replace(/^\s+|\s+$/g, '');
	var access_key = (document.getElementById('tc[gateways][novalnet_payment][access_key]').value).replace(/^\s+|\s+$/g, '');
	if ( !number_validate(merchant_id) || !number_validate(product_id) || !number_validate(tariff) || !string_validate(auth_code) || !string_validate(access_key) ) { 
		alert(jQuery('#nn_field_error').val());
		e.preventDefault();	
	} else {
		return true;		
	}
}

function number_validate(data) {
	var reg = /^(?:[0-9]+$)/;
    var res = reg.test(data);
    return res;     
}

function string_validate(data) {
	var reg = /^(?:[A-Za-z0-9]+$)/;
    var res = reg.test(data);
    return res;     
}
