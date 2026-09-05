<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- custom stylesheet -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">

    <!-- animate css cdn link -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
    <title>eBazar</title>
</head>

<body>

    <!-- header section start -->
    <header>
        <nav class="navbar">
            <div class="logo">
                <a href="#">e<span>Bazar.</span></a>
            </div>

            <ul class="menu-items">
                <li><a href="/vegbaskets/home">Home</a></li>
                <li><a href="about">About</a></li>
                <li><a href="services">Services</a></li>
                <li><a href="products">Products</a></li>
                <li><a href="contact">Contact</a></li>
            </ul>

            <div class="search-box">
                <div class="search-icon">
                    <span class="icon-search"></span>
                </div>
                <input type="text" placeholder="Search...">
            </div>

            <div class="icon-links">
                <div id="search-btn"><span class="icon-search"></span></div>
                <div id="customer-center"><span class="icon-phone-alt"></span></div>
                <div id="icon-shopping-cart"><span class="icon-cart-arrow-down"><span id="item-counter">0</span></div>
                <div id="login-or-signup"><span class="icon-user"></div>
                <div id="toggle-bar"><span class="toggler"></span></div>
            </div>
        </nav>
    </header>
    <!-- header section end -->
    
    <?= $this->renderSection('content') ?>





    <!-- section content area start -->
    <div class="section-content">

       





        <!-- footer area start -->
        <div id="footer">
            <!-- subscribe area start -->
            <div class="subscribe-area">
                <div class="section-wrap">
                    <div class="subscribe-wrap">
                        <div class="subscribe-text">
                            <p>Subscribe to get all product updates first</p>
                        </div>

                        <form class="subscribe-input">
                            <input type="email" placeholder="Enter your email" required>
                            <button type="submit">Subscribe</button>
                        </form>
                    </div>
                </div>
            </div>
            <!-- subscribe area end -->


            <!-- footer navigation area start -->
            <div class="section-footer">
                <div class="section-wrap">
                    <div class="footer-wrap">
                        <div class="company-details">
                            <h2>Fruty</h2>

                            <div class="fdetails">
                                <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Architecto deserunt et
                                    distinctio Animi
                                    possimus sed.</p>
                            </div>

                            <div class="social-media-links">
                                <div class="f-links">
                                    <a href="#"><span class="icon-facebook-f"></span></a>
                                </div>

                                <div class="f-links">
                                    <a href="#"><span class="icon-instagram"></span></a>
                                </div>

                                <div class="f-links">
                                    <a href="#"><span class="icon-linkedin-in"></span></a>
                                </div>

                                <div class="f-links">
                                    <a href="#"><span class="icon-twitter"></span></a>
                                </div>

                                <div class="f-links">
                                    <a href="#"><span class="icon-telegram-plane"></span></a>
                                </div>
                            </div>
                        </div>

                        <div class="footer-menu">
                            <h2>Menu</h2>
                            <div class="fmenu">
                                <p><a href="/vegbaskets/home">Home</a></p>
                                <p><a href="about">About</a></p>
                                <p><a href="services">Services</a></p>
                                <p><a href="products">Products</a></p>
                                <p><a href="contact">Contact</a></p>
                            </div>
                        </div>

                        <div class="top-products-links">
                            <h2>Top Products</h2>
                            <div class="flinks">
                                <p><a href="#products">Apple</a></p>
                                <p><a href="#products">Grapes</a></p>
                                <p><a href="#products">Mangos</a></p>
                                <p><a href="#products">Oranges</a></p>
                                <p><a href="#products">Pinapple</a></p>
                            </div>
                        </div>

                        <div class="useful-links">
                            <h2>Quick Links</h2>
                            <div class="Qlinks">
                                <p><a href="#">User Account</a></p>
                                <p><a href="#">Become An Affilate</a></p>
                                <p><a href="#">New Offer</a></p>
                                <p><a href="#">Recent Blogs</a></p>
                                <p><a href="#">Help</a></p>
                            </div>
                        </div>

                        <div class="master-cards">
                            <h2>Easy Payment</h2>
                            <div class="payment-cards">
                                <div class="payment-link">
                                    <a href="#"><span class="icon-cc-amazon-pay"></span></a>
                                </div>

                                <div class="payment-link">
                                    <a href="#"><span class="icon-cc-mastercard"></span></a>
                                </div>

                                <div class="payment-link">
                                    <a href="#"><span class="icon-cc-paypal"></span></a>
                                </div>

                                <div class="payment-link">
                                    <a href="#"><span class="icon-cc-stripe"></span></a>
                                </div>

                                <div class="payment-link">
                                    <a href="#"><span class="icon-cc-visa"></span></a>
                                </div>

                                <div class="payment-link">
                                    <a href="#"><span class="icon-cc-discover"></span></a>
                                </div>

                                <div class="payment-link">
                                    <a href="#"><span class="icon-cc-jcb"></span></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- footer navigation area end -->


            <!-- copyright area start -->
            <footer>
                <p>Created By <a href="https://www.youtube.com/channel/UCr4TC9YxsDZwzwIxnZOVlBw">Front End Library</a> |
                    &copy;
                    2021 All rights reserved</p>
            </footer>
            <!-- copyright area end -->

        </div>
        <!-- footer area end -->

    </div>
    <!-- section content area end -->



    <!-- costom js -->
    <script src="script.js"></script>

</body>

</html>