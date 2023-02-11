<?php namespace Darryldecode\Cart;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Darryldecode\Cart\Exceptions\InvalidItemException;
use Darryldecode\Cart\Helpers\Helpers;
use Darryldecode\Cart\Validators\CartItemValidator;
use Darryldecode\Cart\Exceptions\UnknownModelException;

/**
 * Class Cart
 * @package Darryldecode\Cart
 */
class Cart
{

    /**
     * the item storage
     *
     * @var
     */
    protected $session;

    /**
     * the event dispatcher
     *
     * @var
     */
    protected $events;

    /**
     * the cart session key
     *
     * @var
     */
    protected $instanceName;

    /**
     * the session key use for the cart
     *
     * @var
     */
    protected $sessionKey;

    /**
     * the session key use to persist cart items
     *
     * @var
     */
    protected $sessionKeyCartItems;

    /**
     * Configuration to pass to ItemCollection
     *
     * @var
     */
    protected $config;

    /**
     * This holds the currently added item id in cart for association
     *
     * @var
     */
    protected $currentItemId;

    /**
     * our object constructor
     *
     * @param $session
     * @param $events
     * @param $instanceName
     * @param $session_key
     * @param $config
     */
    public function __construct($session, $events, $instanceName, $session_key, $config)
    {
        $this->events = $events;
        $this->session = $session;
        $this->instanceName = $instanceName;
        $this->sessionKey = $session_key;
        $this->sessionKeyCartItems = $this->sessionKey . '_cart_items';
        $this->config = $config;
        $this->currentItem = null;
        $this->fireEvent('created');
    }

    /**
     * sets the session key
     *
     * @param string $sessionKey the session key or identifier
     * @return $this|bool
     * @throws \Exception
     */
    public function session($sessionKey)
    {
        if (!$sessionKey) throw new \Exception("Session key is required.");

        $this->sessionKey = $sessionKey;
        $this->sessionKeyCartItems = $this->sessionKey . '_cart_items';

        return $this;
    }

    /**
     * get instance name of the cart
     *
     * @return string
     */
    public function getInstanceName()
    {
        return $this->instanceName;
    }

    /**
     * get an item on a cart by item ID
     *
     * @param $itemId
     * @return mixed
     */
    public function get($itemId)
    {
        return $this->getContent()->get($itemId);
    }

    /**
     * check if an item exists by item ID
     *
     * @param $itemId
     * @return bool
     */
    public function has($itemId)
    {
        return $this->getContent()->has($itemId);
    }

    /**
     * add item to the cart, it can be an array or multi dimensional array
     *
     * @param string|array $id
     * @param string $name
     * @param float $price
     * @param int $quantity
     * @param array $attributes
     * @param string $associatedModel
     * @return $this
     * @throws InvalidItemException
     */
    public function add($id, $name = null, $price_pln = null, $price_eur = null, $quantity = null, $attributes = array(), $associatedModel = null)
    {
        // if the first argument is an array,
        // we will need to call add again
        if (is_array($id)) {
            $attributes = $id['attributes'];
            if($attributes['option_id'] != null && strpos($id['id'], '_') == false)
            {
                $id['id']= $id['id'] . '_' . $attributes['option_id'];
            }

            $this->add(
                $id['id'],
                $id['name'],
                $id['price_pln'],
                $id['price_eur'],
                $id['quantity'],
                Helpers::issetAndHasValueOrAssignDefault($attributes, array()),
                Helpers::issetAndHasValueOrAssignDefault($id['associatedModel'], null)
            );

            return $this;
        }
        if($attributes['option_id'] != null && strpos($id, '_') == false)
        {
            $id = $id . '_' . $attributes['option_id'];
        }

        $data = array(
            'id' => $id,
            'name' => $name,
            'price_pln' => Helpers::normalizePrice($price_pln),
            'price_eur' => Helpers::normalizePrice($price_eur),
            'quantity' => $quantity,
            'attributes' => new ItemAttributeCollection($attributes),
        );

        if (isset($associatedModel) && $associatedModel != '') {
            $data['associatedModel'] = $associatedModel;
        }

        // validate data
        $item = $this->validate($data);

        // get the cart
        $cart = $this->getContent();

        // if the item is already in the cart we will just update it
        if ($cart->has($id)) {
            if($cart->get($id)->attributes->option_id != null && $cart->get($id)->attributes->option_id == $attributes['option_id']){
                $this->update($id, $item);
            }
            elseif($cart->get($id)->attributes->option_id == null){
                $this->update($id, $item);
            }
            else{
                $this->addRow($id, $item);
            }
        } else {
            $this->addRow($id, $item);
        }

        $this->currentItemId = $id;

        return $this;
    }

    /**
     * update a cart
     *
     * @param $id
     * @param array $data
     *
     * the $data will be an associative array, you don't need to pass all the data, only the key value
     * of the item you want to update on it
     * @return bool
     */
    public function update($id, $data)
    {
        if(isset($data['attributes'])){
            if($data['attributes']->option_id != null && strpos($id, '_') == false)
            {
                $id = $id . '_' . $data['attributes']->option_id;
            }
        }

        if ($this->fireEvent('updating', $data) === false) {
            return false;
        }

        $cart = $this->getContent();

        $item = $cart->pull($id);

        foreach ($data as $key => $value) {
            // if the key is currently "quantity" we will need to check if an arithmetic
            // symbol is present so we can decide if the update of quantity is being added
            // or being reduced.
            if ($key == 'quantity') {
                // we will check if quantity value provided is array,
                // if it is, we will need to check if a key "relative" is set
                // and we will evaluate its value if true or false,
                // this tells us how to treat the quantity value if it should be updated
                // relatively to its current quantity value or just totally replace the value
                if (is_array($value)) {
                    if (isset($value['relative'])) {
                        if ((bool)$value['relative']) {
                            $item = $this->updateQuantityRelative($item, $key, $value['value']);
                        } else {
                            $item = $this->updateQuantityNotRelative($item, $key, $value['value']);
                        }
                    }
                } else {
                    $item = $this->updateQuantityRelative($item, $key, $value);
                }
            } elseif ($key == 'attributes') {
                $item[$key] = new ItemAttributeCollection($value);
            } else {
                $item[$key] = $value;
            }
        }

        $cart->put($id, $item);

        $this->save($cart);

        $this->fireEvent('updated', $item);
        return true;
    }

    /**
     * removes an item on cart by item ID
     *
     * @param $id
     * @return bool
     */
    public function remove($id)
    {
        $cart = $this->getContent();

        if ($this->fireEvent('removing', $id) === false) {
            return false;
        }

        $cart->forget($id);

        $this->save($cart);

        $this->fireEvent('removed', $id);
        return true;
    }

    /**
     * clear cart
     * @return bool
     */
    public function clear()
    {
        if ($this->fireEvent('clearing') === false) {
            return false;
        }

        $this->session->put(
            $this->sessionKeyCartItems,
            array()
        );

        $this->fireEvent('cleared');
        return true;
    }


    /**
     * get cart sub total without conditions
     * @param bool $formatted
     * @return float
     */
    public function getSubTotalWithoutConditions($formatted = true)
    {
        $cart = $this->getContent();

        $sum = $cart->sum(function ($item) {
            return $item->getPriceSum();
        });

        return Helpers::formatValue(floatval($sum), $formatted, $this->config);
    }

    /**
     * get cart sub total
     * @param bool $formatted
     * @return float
     */
    public function getSubTotal($formatted = true)
    {
        $cart = $this->getContent();

        $sum = $cart->sum(function (ItemCollection $item) {
            return $item->getPriceSum();
        });

        return Helpers::formatValue(floatval($sum), $formatted, $this->config);

    }

    /**
     * the new total in which conditions are already applied
     *
     * @return float
     */
    public function getTotal()
    {
        $subTotal = $this->getSubTotal(false);

        $newTotal = 0.00;

        $process = 0;

        return Helpers::formatValue($subTotal, $this->config['format_numbers'], $this->config);

    }

    /**
     * get total quantity of items in the cart
     *
     * @return int
     */
    public function getTotalQuantity()
    {
        $items = $this->getContent();

        if ($items->isEmpty()) return 0;

        $count = $items->sum(function ($item) {
            return $item['quantity'];
        });

        return $count;
    }

    /**
     * get the cart
     *
     * @return CartCollection
     */
    public function getContent()
    {
        return (new CartCollection($this->session->get($this->sessionKeyCartItems)))->reject(function($item) {
            return ! ($item instanceof ItemCollection);
        });
    }

    /**
     * check if cart is empty
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->getContent()->isEmpty();
    }

    /**
     * validate Item data
     *
     * @param $item
     * @return array $item;
     * @throws InvalidItemException
     */
    protected function validate($item)
    {
        $rules = array(
            'id' => 'required',
            'price_pln' => 'required|numeric',
            'price_eur' => 'required|numeric',
            'quantity' => 'required|numeric|min:0.1',
            'name' => 'required',
        );

        $validator = CartItemValidator::make($item, $rules);

        if ($validator->fails()) {
            throw new InvalidItemException($validator->messages()->first());
        }

        return $item;
    }

    /**
     * add row to cart collection
     *
     * @param $id
     * @param $item
     * @return bool
     */
    protected function addRow($id, $item)
    {
        if ($this->fireEvent('adding', $item) === false) {
            return false;
        }

        $cart = $this->getContent();

        $cart->put($id, new ItemCollection($item, $this->config));

        $this->save($cart);

        $this->fireEvent('added', $item);

        return true;
    }

    /**
     * save the cart
     *
     * @param $cart CartCollection
     */
    protected function save($cart)
    {
        $this->session->put($this->sessionKeyCartItems, $cart);
    }

    /**
     * update a cart item quantity relative to its current quantity
     *
     * @param $item
     * @param $key
     * @param $value
     * @return mixed
     */
    protected function updateQuantityRelative($item, $key, $value)
    {
        if (preg_match('/\-/', $value) == 1) {
            $value = (int)str_replace('-', '', $value);

            // we will not allowed to reduced quantity to 0, so if the given value
            // would result to item quantity of 0, we will not do it.
            if (($item[$key] - $value) > 0) {
                $item[$key] -= $value;
            }
        } elseif (preg_match('/\+/', $value) == 1) {
            $item[$key] += (int)str_replace('+', '', $value);
        } else {
            $item[$key] += (int)$value;
        }

        return $item;
    }

    /**
     * update cart item quantity not relative to its current quantity value
     *
     * @param $item
     * @param $key
     * @param $value
     * @return mixed
     */
    protected function updateQuantityNotRelative($item, $key, $value)
    {
        $item[$key] = (int)$value;

        return $item;
    }

    /**
     * Setter for decimals. Change value on demand.
     * @param $decimals
     */
    public function setDecimals($decimals)
    {
        $this->decimals = $decimals;
    }

    /**
     * Setter for decimals point. Change value on demand.
     * @param $dec_point
     */
    public function setDecPoint($dec_point)
    {
        $this->dec_point = $dec_point;
    }

    public function setThousandsSep($thousands_sep)
    {
        $this->thousands_sep = $thousands_sep;
    }

    /**
     * @param $name
     * @param $value
     * @return mixed
     */
    protected function fireEvent($name, $value = [])
    {
        return $this->events->dispatch($this->getInstanceName() . '.' . $name, array_values([$value, $this]), true);
    }

    /**
     * Associate the cart item with the given id with the given model.
     *
     * @param string $id
     * @param mixed  $model
     *
     * @return void
     */
    public function associate($model)
    {
        if (is_string($model) && !class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cart = $this->getContent();

        $item = $cart->pull($this->currentItemId);

        $item['associatedModel'] = $model;

        $cart->put($this->currentItemId, new ItemCollection($item, $this->config));

        $this->save($cart);

        return $this;
    }
}
