<%-- redeclare Simple theme includes to keep correct inclusion order --%>
<% require themedCSS('reset') %>
<% require themedCSS('typography') %>
<% require themedCSS('layout') %>
<%-- FoxyStripe requirements --%>
<% require css('foxystripe/css/foxycart.css') %>


<div class="content-container unit ProductPage">
	<article>
		<h1>$Title</h1>
		
		<% if $Content %><div class="typography">$Content</div><% end_if %>

        <% if $Orders %>
            <% loop $Orders %>
                <div class="historySummary line">
                    <div class="sidebar size1of4 unit">
                        <h3>$TransactionDate.NiceUS</h3>
                        <p>
	                        <a href="$ReceiptURL" target="_blank">View Invoice</a><br>
	                        Order #{$Order_ID}<br>
	                        Total $OrderTotal.Nice
	                    </p>
                    </div>
                    <div class="size3of4 lastUnit">
	                    <% loop $Details %>
                            <div class="unit size1of5 productSummaryImage">
                                <% if $Product %>
                                	<a href="{$Product.Link}" title="{$Product.Title}" class="anchor-fix product-image">
                                <% end_if %>
                                <img src="$ProductImage">
                                <% if $Product %></a><% end_if %>
                            </div>
                            <div class="unit size4of5 productSummaryText">
                                <h3>
                                    <% if $Product %><a href="{$Product.Link}" title="{$ProductName.XML}"><% end_if %>
                                        $ProductName
                                    <% if $Product %></a><% end_if %>
                                </h3>
                                <p>
                                    <% if $OrderOptions %>
                                        <% loop $OrderOptions %>
                                            <b>$Name</b>: $Value<br>
                                        <% end_loop %>
                                    <% end_if %>
                                    <b>Quantity</b>: $Quantity<br>
                                    <b>Price:</b> $Price.Nice
                                </p>
                            </div>
                            <br style="clear: both;">
	                    <% end_loop %>
                    </div>
                </div>
            <% end_loop %>

            <% with $Orders %>
                <% include Pagination %>
            <% end_with %>

        <% else %>
            <p>No past orders.</p>
        <% end_if %>

	</article>
</div>