// Cart data
let cart = [];
let selectedCupSize = {}; // Stores { productId: { cupId, cupSize, price } }

// POS JavaScript loaded
console.log('POS JavaScript loaded successfully!');

// Security: Get CSRF token from config
function getCSRFToken() {
    return window.POS_CONFIG?.csrfToken || '';
}

// Helper function to make secure fetch requests
async function secureFetch(url, options = {}) {
    const csrfToken = getCSRFToken();

    // Add CSRF token to headers
    options.headers = {
        ...options.headers,
        'X-CSRF-TOKEN': csrfToken
    };

    return fetch(url, options);
}

// Select cup size for drink
function selectCupSize(button, event) {
    if (event) {
        event.stopPropagation();
    }

    const productCard = button.closest('.product-card');
    const productId = productCard.dataset.id;
    const cupId = parseInt(button.dataset.cupId);
    const cupSize = button.dataset.cupSize;
    const price = parseFloat(button.dataset.price);

    // Clear previous selection for this product
    const cupButtons = productCard.querySelectorAll('.cup-btn');
    cupButtons.forEach(btn => btn.classList.remove('selected'));

    // Select current button
    button.classList.add('selected');

    // Store selected cup size with all details
    selectedCupSize[productId] = {
        cupId: cupId,
        cupSize: cupSize,
        price: price
    };

    // Update the displayed price on the product card
    const priceDiv = productCard.querySelector('.price');
    if (priceDiv) {
        priceDiv.textContent = '₱' + price.toFixed(2);
    }

    // Update the data-price attribute for addToCart
    productCard.dataset.price = price;

    console.log('Selected cup size:', productId, selectedCupSize[productId]);

    // Automatically add to cart after cup size selection
    setTimeout(() => {
        addToCart(productCard);
    }, 100);
}

// Handle product card click
function handleProductClick(element, event) {
    console.log('Product clicked:', element.dataset);
    const isDrink = element.dataset.isDrink === 'true';
    const hasCupSizes = element.dataset.cupSizes && element.dataset.cupSizes !== '[]';
    console.log('Is drink:', isDrink, 'Has cup sizes:', hasCupSizes);

    if (isDrink && hasCupSizes) {
        // For drinks with cup sizes, require cup size selection
        const productId = element.dataset.id;
        console.log('Product ID:', productId);
        console.log('Selected cup sizes:', selectedCupSize);

        if (!selectedCupSize[productId]) {
            alert('Please select a cup size for this drink!');
            return;
        }
        // If cup size is selected, add to cart
        addToCart(element);
    } else {
        // For non-drinks or drinks without cup sizes, add directly to cart
        addToCart(element);
    }
}

// Add product to cart
function addToCart(element) {
    console.log('Adding product:', element.dataset); // Debug log

    const productId = element.dataset.id;
    const productCode = element.dataset.code;
    const productName = element.dataset.name;
    const productStock = parseInt(element.dataset.stock);
    const isDrink = element.dataset.isDrink === 'true';
    const hasCupSizes = element.dataset.cupSizes && element.dataset.cupSizes !== '[]';

    // Get price and cup details
    let productPrice, cupSize, cupId;

    if (isDrink && hasCupSizes && selectedCupSize[productId]) {
        // Use selected cup size price
        productPrice = selectedCupSize[productId].price;
        cupSize = selectedCupSize[productId].cupSize;
        cupId = selectedCupSize[productId].cupId;
    } else {
        // Use base product price
        productPrice = parseFloat(element.dataset.price);
        cupSize = 'none';
        cupId = null;
    }

    console.log('Product details:', { productId, productCode, productName, productPrice, productStock, isDrink, cupSize, cupId }); // Debug log

    // Check if it's a drink with cup sizes and cup size is selected
    if (isDrink && hasCupSizes && !selectedCupSize[productId]) {
        alert('Please select a cup size for this drink!');
        return;
    }

    if (productStock <= 0 && !isDrink) {
        alert('Product is out of stock!');
        return;
    }
    
    // Create unique key for cart items (product + cup size)
    const cartKey = (isDrink && cupId) ? `${productId}_${cupId}` : productId;
    
    // Check if product already in cart
    const existingItem = cart.find(item => item.cartKey === cartKey);
    
    if (existingItem) {
        console.log('Product already in cart, updating quantity'); // Debug log
        if (existingItem.quantity < productStock || isDrink) {
            existingItem.quantity++;
            existingItem.subtotal = existingItem.quantity * existingItem.price;
            console.log('Updated existing item:', existingItem); // Debug log
        } else {
            alert('Cannot add more. Insufficient stock!');
            return;
        }
    } else {
        console.log('Adding new product to cart'); // Debug log
        const newItem = {
            cartKey: cartKey,
            id: productId,
            code: productCode,
            name: productName,
            price: productPrice,
            quantity: 1,
            stock: productStock,
            subtotal: productPrice,
            cupSize: cupSize,
            cupId: cupId,
            isDrink: isDrink
        };
        console.log('New item created:', newItem); // Debug log
        cart.push(newItem);
    }
    
    console.log('Cart after adding:', cart); // Debug log
    updateCart();
}

// Update cart display
function updateCart() {
    const cartItemsDiv = document.getElementById('cartItems');
    
    if (cart.length === 0) {
        cartItemsDiv.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 40px 0;">Cart is empty</p>';
    } else {
        cartItemsDiv.innerHTML = cart.map((item, index) => {
            const displayName = item.cupSize && item.cupSize !== 'none' 
                ? `${item.name} (${item.cupSize})` 
                : item.name;
            return `
            <div class="cart-item" data-sale-item-id="${item.id || 'temp-' + index}" data-index="${index}">
                <div class="cart-item-details" style="flex: 1;">
                    <h4 style="margin: 0 0 4px 0; font-size: 13px;">${displayName}</h4>
                    <p style="margin: 0; font-size: 12px; color: #666;">₱${item.price.toFixed(2)} × ${item.quantity}</p>
                </div>
                <div class="cart-item-actions" style="display: flex; align-items: center; gap: 8px;">
                    <strong style="color: var(--primary); min-width: 70px; text-align: right;">₱${item.subtotal.toFixed(2)}</strong>
                    <div class="quantity-control" style="display: flex; align-items: center; gap: 4px;">
                        <button type="button" class="quantity-btn" onclick="decreaseQuantity(${index})" style="width: 26px; height: 26px; border: none; border-radius: 4px; background: #007bff; color: white; cursor: pointer; font-weight: bold; font-size: 14px;">-</button>
                        <span style="font-weight: 600; min-width: 24px; text-align: center; font-size: 12px;">${item.quantity}</span>
                        <button type="button" class="quantity-btn" onclick="increaseQuantity(${index})" style="width: 26px; height: 26px; border: none; border-radius: 4px; background: #007bff; color: white; cursor: pointer; font-weight: bold; font-size: 14px;">+</button>
                    </div>
                    <button type="button" class="btn-void-item" onclick="openItemVoidModal(${index})" style="padding: 4px 10px; background: #dc3545; color: white; border: none; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer;">Void</button>
                </div>
            </div>
        `}).join('');
    }
    
    updateTotals();
}

// Update quantity
function updateQuantity(index, newQuantity) {
    const qty = parseInt(newQuantity);
    
    if (isNaN(qty) || qty < 1) {
        alert('Quantity must be at least 1');
        updateCart();
        return;
    }
    
    if (qty > cart[index].stock) {
        alert('Cannot add more. Insufficient stock!');
        updateCart();
        return;
    }
    
    cart[index].quantity = qty;
    cart[index].subtotal = cart[index].quantity * cart[index].price;
    updateCart();
}

// Increase quantity
function increaseQuantity(index) {
    if (cart[index].quantity < cart[index].stock) {
        cart[index].quantity++;
        cart[index].subtotal = cart[index].quantity * cart[index].price;
        updateCart();
    } else {
        alert('Cannot add more. Insufficient stock!');
    }
}

// Decrease quantity
function decreaseQuantity(index) {
    if (cart[index].quantity > 1) {
        cart[index].quantity--;
        cart[index].subtotal = cart[index].quantity * cart[index].price;
        updateCart();
    }
}

// Remove from cart
function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
}



// Return cart (clear all items)
function returnCart() {
    if (cart.length === 0) {
        alert('Cart is already empty!');
        return;
    }
    
    if (confirm('Remove all items from cart? This action cannot be undone.')) {
        cart = [];
        selectedCupSize = {};
        updateCart();
        alert('✓ Cart cleared successfully!');
    }
}

// Update totals
function updateTotals() {
    console.log('=== UPDATE TOTALS CALLED ==='); // Debug log
    console.log('Current cart:', cart); // Debug log
    console.log('Cart length:', cart.length); // Debug log
    
    if (!cart || cart.length === 0) {
        console.log('Cart is empty, setting totals to 0'); // Debug log
        document.getElementById('subtotal').textContent = '₱0.00';
        document.getElementById('tax').textContent = '₱0.00';
        document.getElementById('discount').textContent = '₱0.00';
        document.getElementById('grandTotal').textContent = '₱0.00';
        return;
    }
    
    let subtotal = 0;
    for (let i = 0; i < cart.length; i++) {
        console.log(`Item ${i}:`, cart[i]); // Debug log
        console.log(`Item ${i} subtotal:`, cart[i].subtotal); // Debug log
        subtotal += parseFloat(cart[i].subtotal) || 0;
    }
    
    console.log('Calculated subtotal:', subtotal); // Debug log
    
    const tax = subtotal * 0.12; // 12% tax
    const total = subtotal + tax;
    
    console.log('Final totals:', { subtotal, tax, total }); // Debug log
    
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('tax').textContent = '₱' + tax.toFixed(2);
    document.getElementById('discount').textContent = '₱0.00';
    document.getElementById('grandTotal').textContent = '₱' + total.toFixed(2);
}

// Open checkout modal
function openCheckout() {
    if (cart.length === 0) {
        alert('Cart is empty!');
        return;
    }
    
    // Display cart items in modal
    displayModalCart();
    updateModalTotals();
    
    // Reset form
    document.getElementById('customerName').value = '';
    document.getElementById('discountAmount').value = '0';
    document.getElementById('amountPaid').value = '';
    document.getElementById('changeAmount').value = '';
    
    // Show modal
    document.getElementById('checkoutModal').classList.add('active');
}

// Close checkout modal
function closeCheckout() {
    document.getElementById('checkoutModal').classList.remove('active');
}

// SALE VOID MODAL HELPERS
function openSaleVoidModal() {
    // Check if cart is empty
    if (!cart || cart.length === 0) {
        alert('Cart is empty - nothing to void!');
        return;
    }
    
    // Test if void modal exists
    const voidModal = document.getElementById('voidModal');
    if (!voidModal) {
        alert('Void modal not found!');
        return;
    }
    
    // Test if form exists
    const voidForm = document.getElementById('voidForm');
    if (!voidForm) {
        alert('Void form not found!');
        return;
    }
    
    // Populate items list - simple "Product x Qty" format
    const voidItemsList = document.getElementById('voidItemsList');
    const itemsList = cart.map(item => {
        const displayName = item.cupSize && item.cupSize !== 'none' 
            ? `${item.name} (${item.cupSize})` 
            : item.name;
        return `${displayName} x ${item.quantity}`;
    });
    
    // Display as comma-separated list
    voidItemsList.textContent = itemsList.join(', ');
    
    // Reset form
    document.getElementById('adminPassword').value = '';
    document.getElementById('voidReason').value = '';
    document.getElementById('charCount').textContent = '0';
    document.getElementById('voidModal').classList.add('active');
    setTimeout(() => document.getElementById('adminPassword').focus(), 100);
}

function closeSaleVoidModal() {
    document.getElementById('voidModal').classList.remove('active');
    document.getElementById('voidForm').reset();
}

// sale form char counter
document.getElementById('voidReason')?.addEventListener('input', function() {
    const cnt = this.value.length;
    document.getElementById('charCount').textContent = cnt;
    if (cnt > 500) {
        this.value = this.value.substring(0, 500);
        document.getElementById('charCount').textContent = '500';
    }
});

// handle sale void submission
document.getElementById('voidForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const adminPassword = document.getElementById('adminPassword').value;
    const reason = document.getElementById('voidReason').value.trim();
    if (!reason) {
        alert('Please enter a reason for voiding the sale');
        return;
    }
    if (cart.length === 0) {
        alert('Cart is empty - nothing to void');
        closeSaleVoidModal();
        return;
    }
    
    // Calculate total for logging
    const cartTotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const orig = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Authorizing...';
    try {
        // send cart void request to updated API with CSRF protection
        const response = await secureFetch('api/void_item.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                void_type: 'cart',
                admin_password: adminPassword,
                void_reason: reason,
                total_amount: cartTotal,
                cart_items: cart.map(item => ({
                    product_id: item.id,
                    product_name: item.name,
                    quantity: item.quantity,
                    price: item.price,
                    subtotal: item.subtotal,
                    cup_size: item.cupSize || 'none'
                }))
            })
        });
        const result = await response.json();
        submitBtn.disabled = false;
        submitBtn.textContent = orig;
        if (response.ok && result.success) {
            // Clear cart after successful void
            cart = [];
            selectedCupSize = {};
            updateCart();
            closeSaleVoidModal();
            alert('✓ Cart voided and recorded!\n\nAdmin authorization logged.');
        } else if (response.status === 401) {
            alert('❌ Invalid admin password.\n\nPlease try again.');
        } else if (response.status === 429) {
            alert('⚠️ Too many failed attempts.\n\nPlease wait before trying again.');
        } else {
            alert('Error: ' + (result.error || 'Unable to void cart'));
        }
    } catch(err) {
        submitBtn.disabled = false;
        submitBtn.textContent = orig;
        alert('Error contacting server');
        console.error('Void error:', err);
    }
});

// Display cart items in modal
function displayModalCart() {
    const modalCartItems = document.getElementById('modalCartItems');
    if (!modalCartItems) return;

    const activeItems = cart.filter(item => !item.voided);

    if (activeItems.length === 0) {
        modalCartItems.innerHTML = '<p style="text-align:center;color:var(--text-secondary);padding:16px 0;">No items.</p>';
        return;
    }

    modalCartItems.innerHTML = activeItems.map(item => {
        const name = item.cupSize && item.cupSize !== 'none' ? `${item.name} (${item.cupSize})` : item.name;
        return `
            <div style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);">
                <div style="flex:1;">
                    <strong style="font-size:13px;">${name}</strong><br>
                    <span style="font-size:12px;color:var(--text-secondary);">₱${item.price.toFixed(2)} × ${item.quantity}</span>
                </div>
                <div style="min-width:90px;text-align:right;font-weight:700;">₱${item.subtotal.toFixed(2)}</div>
            </div>
        `;
    }).join('');
}

function updateModalTotals() {
    const activeItems = cart.filter(item => !item.voided);
    const subtotal = activeItems.reduce((sum, item) => sum + (parseFloat(item.subtotal) || 0), 0);
    const tax = subtotal * 0.12;
    const discount = parseFloat(document.getElementById('discountAmount')?.value || 0) || 0;
    const total = Math.max(0, subtotal + tax - discount);

    const modalSubtotal = document.getElementById('modalSubtotal');
    const modalTax = document.getElementById('modalTax');
    const modalDiscount = document.getElementById('modalDiscount');
    const modalGrandTotal = document.getElementById('modalGrandTotal');

    if (modalSubtotal) modalSubtotal.textContent = '₱' + subtotal.toFixed(2);
    if (modalTax) modalTax.textContent = '₱' + tax.toFixed(2);
    if (modalDiscount) modalDiscount.textContent = '₱' + discount.toFixed(2);
    if (modalGrandTotal) modalGrandTotal.textContent = '₱' + total.toFixed(2);

    calculateModalChange();
}

function calculateModalChange() {
    const paid = parseFloat(document.getElementById('amountPaid')?.value || 0) || 0;
    const grandTotalText = document.getElementById('modalGrandTotal')?.textContent || '₱0.00';
    const total = parseFloat(grandTotalText.replace(/[₱,\s]/g, '')) || 0;
    const change = Math.max(0, paid - total);

    const changeEl = document.getElementById('changeAmount');
    if (changeEl) changeEl.value = change.toFixed(2);
}

document.getElementById('amountPaid')?.addEventListener('input', calculateModalChange);

// Update discount in modal
document.getElementById('discountAmount')?.addEventListener('input', updateModalTotals);

// Handle checkout form submission
document.getElementById('checkoutForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    console.log('Checkout form submitted'); // Debug log
    
    // Filter out voided items
    const activeItems = cart.filter(item => !item.voided);
    
    if (activeItems.length === 0) {
        alert('No items to checkout. All items have been voided.');
        return;
    }
    
    const subtotal = activeItems.reduce((sum, item) => sum + item.subtotal, 0);
    const tax = subtotal * 0.12;
    const discount = parseFloat(document.getElementById('discountAmount').value || 0);
    const total = subtotal + tax - discount;
    const paid = parseFloat(document.getElementById('amountPaid').value);
    
    console.log('Calculations:', { subtotal, tax, discount, total, paid }); // Debug log
    
    if (paid < total) {
        alert('Amount paid is less than total!');
        return;
    }
    
    // Prepare sale data for API
    const saleData = {
        customer_name: document.getElementById('customerName').value,
        payment_method: document.getElementById('paymentMethod').value,
        subtotal: subtotal,
        tax: tax,
        discount: discount,
        total: total,
        amount_paid: paid,
        change: paid - total,
        items: activeItems.map(item => ({
            id: item.id,
            name: item.name,
            price: item.price,
            quantity: item.quantity,
            subtotal: item.subtotal,
            cup_size: item.cupSize || 'none',
            cup_id: item.cupId || null
        }))
    };
    
    console.log('Sale data being sent:', saleData); // Debug log
    
    try {
        const response = await secureFetch('api/process-sale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(saleData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Sale completed successfully!\nInvoice: ' + result.invoice);
            cart = [];
            selectedCupSize = {}; // Also reset cup size selections
            updateCart();
            closeCheckout();

            // After clicking OK on the alert, show receipt in the same tab
            const receiptUrl = 'receipt.php?invoice=' + encodeURIComponent(result.invoice);
            window.location.href = receiptUrl;
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error processing sale: ' + error.message);
    }
});

// Search products
document.getElementById('searchProduct')?.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const products = document.querySelectorAll('.product-card');
    
    products.forEach(product => {
        const name = product.dataset.name.toLowerCase();
        const code = product.dataset.code.toLowerCase();
        
        if (name.includes(searchTerm) || code.includes(searchTerm)) {
            product.style.display = 'block';
        } else {
            product.style.display = 'none';
        }
    });
});

// Filter by category
document.getElementById('filterCategory')?.addEventListener('change', function() {
    const categoryId = this.value;
    const products = document.querySelectorAll('.product-card');
    
    products.forEach(product => {
        if (categoryId === '' || product.dataset.category === categoryId) {
            product.style.display = 'block';
        } else {
            product.style.display = 'none';
        }
    });
});

// Close modal on outside click
window.addEventListener('click', function(e) {
    const checkoutModal = document.getElementById('checkoutModal');
    const voidModal = document.getElementById('voidModal');

    if (e.target === checkoutModal) {
        closeCheckout();
    }

    if (e.target === voidModal) {
        closeSaleVoidModal();
    }
});

// ============================================
// CLEAR CART FUNCTIONALITY
// ============================================

/**
 * Clear cart with confirmation (no admin authorization needed)
 * This simply empties the cart - does NOT affect database
 */
function clearCartConfirm() {
    if (!cart || cart.length === 0) {
        alert('Cart is already empty!');
        return;
    }
    
    const itemCount = cart.length;
    const totalAmount = cart.reduce((sum, item) => sum + (item.subtotal || 0), 0);
    
    if (confirm('Clear all ' + itemCount + ' item(s) from the cart?\n\nTotal: ₱' + totalAmount.toFixed(2) + '\n\nThis action cannot be undone.')) {
        clearCartCompletely();
        alert('✓ Cart cleared successfully!');
    }
}

// Make clearCartConfirm globally accessible
window.clearCartConfirm = clearCartConfirm;

/**
 * Clear cart completely - resets all cart state
 */
function clearCartCompletely() {
    // Clear cart array
    cart = [];
    
    // Reset cup size selections
    selectedCupSize = {};
    
    // Reset all cup size buttons in product grid
    document.querySelectorAll('.cup-btn.selected').forEach(btn => {
        btn.classList.remove('selected');
    });
    
    // Reset product prices to base prices
    document.querySelectorAll('.product-card').forEach(card => {
        const priceDiv = card.querySelector('.price');
        const basePrice = priceDiv?.dataset?.basePrice;
        if (basePrice && priceDiv) {
            priceDiv.textContent = '₱' + parseFloat(basePrice).toFixed(2);
            card.dataset.price = basePrice;
        }
    });
    
    // Update cart display
    updateCart();
    
    console.log('Cart cleared successfully');
}

// Close void modal helper
function closeVoidModal() {
    document.getElementById('voidModal').classList.remove('active');
    document.getElementById('voidForm').reset();
}

// Track which item is being voided
let voidingItemIndex = null;

/**
 * Open void modal for a single cart item
 * @param {number} index - The index of the item in the cart array
 */
function openItemVoidModal(index) {
    const item = cart[index];
    if (!item) {
        alert('Item not found in cart');
        return;
    }
    
    voidingItemIndex = index;
    
    // Check if we have the item void modal, otherwise use the regular void modal
    const itemVoidModal = document.getElementById('itemVoidModal');
    
    if (itemVoidModal) {
        // Update item details in the modal
        const displayName = item.cupSize && item.cupSize !== 'none' 
            ? `${item.name} (${item.cupSize})` 
            : item.name;
        document.getElementById('itemVoidName').textContent = displayName;
        document.getElementById('itemVoidQty').textContent = item.quantity;
        document.getElementById('itemVoidPrice').textContent = '₱' + item.price.toFixed(2);
        document.getElementById('itemVoidSubtotal').textContent = '₱' + item.subtotal.toFixed(2);
        
        // Reset form
        document.getElementById('itemVoidAdminPassword').value = '';
        document.getElementById('itemVoidReason').value = '';
        document.getElementById('itemVoidCharCount').textContent = '0';
        
        // Show modal
        itemVoidModal.classList.add('active');
        setTimeout(() => document.getElementById('itemVoidAdminPassword').focus(), 100);
    } else {
        // Fallback: Use a simple confirm for now (will be replaced by modal)
        if (confirm(`Remove "${item.name}" from cart?\n\nThis will remove all ${item.quantity} units.`)) {
            cart.splice(index, 1);
            updateCart();
        }
    }
}

/**
 * Close item void modal
 */
function closeItemVoidModal() {
    const modal = document.getElementById('itemVoidModal');
    if (modal) {
        modal.classList.remove('active');
        const form = document.getElementById('itemVoidForm');
        if (form) form.reset();
    }
    voidingItemIndex = null;
}

/**
 * Handle item void form submission
 */
async function handleItemVoid(e) {
    e.preventDefault();
    
    if (voidingItemIndex === null || !cart[voidingItemIndex]) {
        alert('No item selected for void');
        closeItemVoidModal();
        return;
    }
    
    const item = cart[voidingItemIndex];
    const adminPassword = document.getElementById('itemVoidAdminPassword').value;
    const reason = document.getElementById('itemVoidReason').value.trim();
    
    if (!reason) {
        alert('Please enter a reason for voiding this item');
        return;
    }
    
    const submitBtn = document.querySelector('#itemVoidForm button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Authorizing...';
    
    try {
        const response = await secureFetch('api/void_item.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                void_type: 'cart_item',
                admin_password: adminPassword,
                void_reason: reason,
                item: {
                    product_id: item.id,
                    product_name: item.name,
                    quantity: item.quantity,
                    price: item.price,
                    subtotal: item.subtotal,
                    cup_size: item.cupSize || 'none'
                }
            })
        });
        
        const result = await response.json();
        
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        
        if (response.ok && result.success) {
            // Remove item from cart
            cart.splice(voidingItemIndex, 1);
            updateCart();
            closeItemVoidModal();
            alert('✓ Item voided successfully!\n\nAdmin authorization logged.');
        } else if (response.status === 401) {
            alert('❌ Invalid admin password.\n\nPlease try again.');
        } else if (response.status === 429) {
            alert('⚠️ Too many failed attempts.\n\nPlease wait before trying again.');
        } else {
            alert('Error: ' + (result.error || 'Unable to void item'));
        }
    } catch (err) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        alert('Error contacting server');
        console.error('Void error:', err);
    }
}

// Make all functions globally accessible
window.clearCartConfirm = clearCartConfirm;
window.clearCartCompletely = clearCartCompletely;
window.openSaleVoidModal = openSaleVoidModal;
window.closeSaleVoidModal = closeSaleVoidModal;
window.closeVoidModal = closeVoidModal;
window.openItemVoidModal = openItemVoidModal;
window.closeItemVoidModal = closeItemVoidModal;
window.handleItemVoid = handleItemVoid;
window.openCheckout = openCheckout;
window.closeCheckout = closeCheckout;
window.updateCart = updateCart;

// ============================================
// CART BUTTON EVENT LISTENERS
// ============================================
(function initCartButtons() {
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupCartButtons);
    } else {
        setupCartButtons();
    }
    
    function setupCartButtons() {
        // Clear Cart Button
        const clearBtn = document.getElementById('clearCartBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                clearCartConfirm();
            });
        }

        // Void Cart Button
        const voidBtn = document.getElementById('voidCartBtn');
        if (voidBtn) {
            voidBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openSaleVoidModal();
            });
        }

        // Complete Sale Button
        const completeBtn = document.getElementById('completeSaleBtn');
        if (completeBtn) {
            completeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openCheckout();
            });
        }
        
        console.log('Cart buttons initialized');
    }
})();
