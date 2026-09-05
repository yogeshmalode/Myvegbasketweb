<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index()
    {
        return view('welcome_message');
    }

  /*  public function about()
    {
        return view('about.php');
        
    }
    public function services()
    {
        return view('services.php');
    }
    public function products()
    {
        return view('products.php');
    }
    public function gallary()
    {
        return view('gallary.php');
    }
    public function contact()
    {
        return view('contact.php');
    }*/
}
