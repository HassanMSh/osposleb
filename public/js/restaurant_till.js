/** Separates payment controls while keeping payment history in the bill's original order. */
document.addEventListener("DOMContentLoaded", () => {
    const register = document.querySelector(".till-restaurant");
    if (!register) {
        return;
    }

    const restaurantMenuCategoryKey = "restaurantMenuCategory";

    /** Selects a restaurant menu tab and shows its matching category. */
    function selectRestaurantCategory(selectedCategory) {
        const categoryTabs = Array.from(register.querySelectorAll("[data-restaurant-category]"));
        const selectedTab = categoryTabs.find((categoryTab) => categoryTab.dataset.restaurantCategory === selectedCategory);
        if (!selectedTab) {
            return false;
        }

        categoryTabs.forEach((categoryTab) => {
            const selected = categoryTab === selectedTab;
            categoryTab.setAttribute("aria-selected", selected ? "true" : "false");
            categoryTab.classList.toggle("active", selected);
        });
        register.querySelectorAll(".restaurant-menu-category").forEach((categoryPanel) => {
            categoryPanel.hidden = categoryPanel.id !== "restaurant-menu-category-" + selectedCategory;
        });

        return true;
    }

    const saleHasLines = register.querySelector('#cart_contents form[id^="cart_"]');
    if (!saleHasLines) {
        try {
            window.sessionStorage.removeItem(restaurantMenuCategoryKey);
        } catch {}
    } else {
        let savedCategory = null;
        try {
            savedCategory = window.sessionStorage.getItem(restaurantMenuCategoryKey);
        } catch {}

        if (savedCategory !== null && !selectRestaurantCategory(savedCategory)) {
            try {
                window.sessionStorage.removeItem(restaurantMenuCategoryKey);
            } catch {}
        }
    }

    const sale = document.querySelector("#overall_sale");
    const saleBody = sale?.querySelector(":scope > .panel-body");
    const paymentDetails = sale?.querySelector("#payment_details");
    const paymentHistory = paymentDetails?.querySelector("table#register");
    if (sale && saleBody && paymentDetails) {
        if (paymentHistory) {
            const saleActions = saleBody.querySelector("#buttons_form");
            if (saleActions) {
                saleBody.insertBefore(paymentHistory, saleActions);
            } else {
                saleBody.append(paymentHistory);
            }
        }
        sale.parentElement.append(paymentDetails);
    }

    document.addEventListener("click", (event) => {
        const tab = event.target.closest("[data-restaurant-category]");
        if (!tab) {
            return;
        }

        if (!register.contains(tab)) {
            return;
        }

        const selectedCategory = tab.dataset.restaurantCategory;
        selectRestaurantCategory(selectedCategory);
        try {
            window.sessionStorage.setItem(restaurantMenuCategoryKey, selectedCategory);
        } catch {}
    });

    /** Saves the selected discount mode and clears the old value when that mode changes. */
    const submitDiscountType = (discountToggle, resetDiscount = false) => {
        if (discountToggle.dataset.discountSubmitPending) {
            return;
        }

        discountToggle.dataset.discountSubmitPending = "1";
        window.setTimeout(() => {
            const cartForm = document.getElementById("cart_" + discountToggle.dataset.line);
            if (!cartForm) {
                delete discountToggle.dataset.discountSubmitPending;
                return;
            }

            let discountType = cartForm.querySelector('input[name="discount_type"]');
            if (!discountType) {
                discountType = register.querySelector(`input[name="discount_type"][form="${cartForm.id}"]`);
            }
            if (!discountType) {
                discountType = document.createElement("input");
                discountType.type = "hidden";
                discountType.name = "discount_type";
                cartForm.append(discountType);
            }
            discountType.value = discountToggle.checked ? "1" : "0";
            if (resetDiscount) {
                let discountInput = cartForm.querySelector('input[name="discount"]');
                if (!discountInput) {
                    discountInput = register.querySelector(`input[name="discount"][form="${cartForm.id}"]`);
                }
                if (discountInput) {
                    discountInput.value = "0";
                }
            }
            cartForm.requestSubmit();
        }, 0);
    };

    register.addEventListener("click", (event) => {
        const toggle = event.target.closest(".toggle");
        if (!toggle || !register.contains(toggle)) {
            return;
        }

        const discountToggle = toggle.querySelector('input[name="discount_toggle"]');
        if (discountToggle) {
            submitDiscountType(discountToggle, true);
        }
    });

    register.addEventListener("change", (event) => {
        const discountInput = event.target.closest('input[name="discount"]');
        if (discountInput) {
            const cartForm = document.getElementById(discountInput.getAttribute("form"));
            const discountToggle = cartForm?.querySelector('input[name="discount_toggle"]');
            if (discountToggle) {
                submitDiscountType(discountToggle);
            } else if (cartForm) {
                cartForm.requestSubmit();
            }
            return;
        }

        const discountToggle = event.target.closest('input[name="discount_toggle"]');
        if (discountToggle) {
            submitDiscountType(discountToggle, true);
        }
    });
});
