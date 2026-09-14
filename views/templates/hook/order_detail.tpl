{**
 * Invoice link on the customer's order detail page (hook displayOrderDetail).
 *}
<section class="box billto-invoice">
  <h3>{l s='Faktura' mod='billtoinvoices'}</h3>
  <p>
    {if $billto_invoice_number}{$billto_invoice_number|escape:'html':'UTF-8'}: {/if}
    <a href="{$billto_pdf_url|escape:'html':'UTF-8'}" target="_blank">{l s='Pobierz PDF' mod='billtoinvoices'}</a>
    {if $billto_public_url} | <a href="{$billto_public_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Dane do przelewu' mod='billtoinvoices'}</a>{/if}
  </p>
</section>
