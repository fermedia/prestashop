{capture name=path}{l s='Shipping' mod='billmatebank'}{/capture}
{include file="$tpl_dir./breadcrumb.tpl"}
<div id="order_area">
<h2>{l s='Order summation' mod='billmatebank'}</h2>

{assign var='current_step' value='payment'}
{include file="$tpl_dir./order-steps.tpl"}
{if empty($error_message) == false}
<div class="error">
{l s='Payment was not completed. Error code: %1$s' sprintf=[$error_message] mod='billmatebank' }</div>
{/if}

<h3>{l s='Billmate Bank Payment' mod='billmatebank'}</h3>
<br/>
<h2>{l s='Redirecting to gateway website' mod='billmatebank'}..... </h2>
<script type="text/javascript">
		document.location.href = '{$url}';
</script>
<link rel="stylesheet" href="{$smarty.const._MODULE_DIR_}billmatebank/colorbox.css" />
</div>
