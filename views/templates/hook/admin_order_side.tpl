{**
 * BillTo box on the order page (hook displayAdminOrderSide).
 *}
<div class="card mt-2" id="billto-order-card">
  <div class="card-header"><h3 class="card-header-title">BillTo{if $billto_sandbox} <span class="badge badge-warning">sandbox</span>{/if}</h3></div>
  <div class="card-body">
    {if isset($smarty.get.billto_notice)}
      <div class="alert alert-info">{$smarty.get.billto_notice|escape:'html':'UTF-8'}</div>
    {/if}
    {if $billto_record}
      <p class="mb-1"><strong>{l s='Zamówienie' mod='billtoinvoices'}:</strong> {if $billto_record.billto_order_number}{$billto_record.billto_order_number|escape:'html':'UTF-8'}{elseif $billto_record.billto_order_id}{l s='utworzone' mod='billtoinvoices'}{else}<span class="text-muted">{l s='nie wysłano' mod='billtoinvoices'}</span>{/if}</p>
      {if $billto_scenario_label}<p class="mb-1"><strong>{l s='Nabywca' mod='billtoinvoices'}:</strong> {$billto_scenario_label|escape:'html':'UTF-8'}</p>{/if}
      <p class="mb-1"><strong>{l s='Faktura' mod='billtoinvoices'}:</strong>
        {if $billto_record.invoice_id}
          {$billto_record.invoice_number|escape:'html':'UTF-8'}
          <a href="{$billto_action_url|escape:'html':'UTF-8'}&do=pdf&id_order={$billto_id_order|intval}" target="_blank">PDF</a>
          {if $billto_record.public_url} | <a href="{$billto_record.public_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='strona publiczna' mod='billtoinvoices'}</a>{/if}
          {if $billto_record.invoice_paid === false} <span class="badge badge-warning">{l s='nieopłacona' mod='billtoinvoices'}</span>{/if}
        {else}
          <span class="text-muted">{l s='brak' mod='billtoinvoices'}</span>
        {/if}
      </p>
      {if $billto_record.invoice_id}
        <p class="mb-1"><strong>KSeF:</strong>
          {if $billto_record.ksef_status == 'assigned'}{$billto_record.ksef_number|escape:'html':'UTF-8'}
          {elseif $billto_record.ksef_status == 'pending'}{l s='w trakcie nadawania numeru' mod='billtoinvoices'}
          {elseif $billto_record.ksef_status == 'error'}<span class="text-danger">{l s='błąd - sprawdź w BillTo' mod='billtoinvoices'}</span>
          {else}{l s='nie wysłano' mod='billtoinvoices'}{/if}
        </p>
      {/if}
      {if $billto_record.corrections}
        <p class="mb-1"><strong>{l s='Korekty' mod='billtoinvoices'}:</strong> {foreach from=$billto_record.corrections item=c name=corr}{$c.invoice_number|escape:'html':'UTF-8'}{if !$smarty.foreach.corr.last}, {/if}{/foreach}</p>
      {/if}
      {if $billto_record.last_error}
        <p class="text-danger mb-1"><strong>{l s='Ostatni błąd' mod='billtoinvoices'}:</strong> {$billto_record.last_error|escape:'html':'UTF-8'}</p>
      {/if}
    {else}
      <p class="text-muted">{l s='Zamówienie nie zostało jeszcze wysłane do BillTo.' mod='billtoinvoices'}</p>
    {/if}
    <div class="mt-2">
      {if !$billto_record || !$billto_record.invoice_id}
        <a class="btn btn-outline-secondary btn-sm" href="{$billto_action_url|escape:'html':'UTF-8'}&do=sync&id_order={$billto_id_order|intval}">{if $billto_record && $billto_record.billto_order_id}{l s='Synchronizuj' mod='billtoinvoices'}{else}{l s='Wyślij zamówienie' mod='billtoinvoices'}{/if}</a>
        <a class="btn btn-primary btn-sm" href="{$billto_action_url|escape:'html':'UTF-8'}&do=invoice&id_order={$billto_id_order|intval}">{l s='Wystaw fakturę' mod='billtoinvoices'}</a>
      {else}
        {if $billto_record.ksef_status != 'assigned'}<a class="btn btn-outline-secondary btn-sm" href="{$billto_action_url|escape:'html':'UTF-8'}&do=ksef&id_order={$billto_id_order|intval}">{l s='Wyślij do KSeF' mod='billtoinvoices'}</a>{/if}
        <a class="btn btn-outline-secondary btn-sm" href="{$billto_action_url|escape:'html':'UTF-8'}&do=ksef-status&id_order={$billto_id_order|intval}">{l s='Odśwież KSeF' mod='billtoinvoices'}</a>
        {if $billto_record.invoice_paid === false}<a class="btn btn-outline-secondary btn-sm" href="{$billto_action_url|escape:'html':'UTF-8'}&do=settle&id_order={$billto_id_order|intval}">{l s='Oznacz jako zapłaconą' mod='billtoinvoices'}</a>{/if}
        <a class="btn btn-outline-secondary btn-sm" href="{$billto_action_url|escape:'html':'UTF-8'}&do=send-email&id_order={$billto_id_order|intval}">{l s='Wyślij e-mailem' mod='billtoinvoices'}</a>
        <a class="btn btn-outline-secondary btn-sm" href="{$billto_action_url|escape:'html':'UTF-8'}&do=refresh-pdf&id_order={$billto_id_order|intval}">{l s='Pobierz PDF ponownie' mod='billtoinvoices'}</a>
      {/if}
    </div>
  </div>
</div>
