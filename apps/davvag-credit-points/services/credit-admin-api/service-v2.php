<?php
namespace davvag_credit_points;

require_once dirname(__DIR__, 2) . "/lib/CreditServiceSupport.php";
require_once dirname(__DIR__, 2) . "/lib/CreditCouponService.php";
require_once dirname(__DIR__, 2) . "/lib/CreditCurrencyAdapter.php";

class CreditAdminApiServiceV2 {
    private function call($res, $callback) {
        try {
            CreditServiceSupport::requireAdmin();
            return $callback();
        } catch (\Throwable $error) {
            return CreditServiceSupport::fail($res, $error);
        }
    }

    private function body($req) {
        return CreditServiceSupport::body($req);
    }

    private function database() {
        return (new CreditLedgerService())->database();
    }

    private function save($table, $body, $fields) {
        $db = $this->database();
        $data = array();
        foreach ($fields as $field) {
            if (property_exists($body, $field)) {
                $data[$field] = $body->{$field};
            }
        }
        if (!$data) {
            throw new CreditException("No supported fields were supplied.");
        }
        if (isset($body->id) && intval($body->id) > 0) {
            $id = intval($body->id);
            if (!$db->one("SELECT id FROM `" . $table . "` WHERE id=? LIMIT 1", "i", array($id))) {
                throw new CreditException("The configuration record was not found.");
            }
            $db->updateById($table, $id, $data);
        } else {
            $id = $db->insert($table, $data);
        }
        return $db->one("SELECT * FROM `" . $table . "` WHERE id=?", "i", array($id));
    }

    private function nonNegativeInteger($value, $label) {
        $text = trim(strval($value));
        if (!preg_match('/^[0-9]+$/', $text) || strlen($text) > 10 || floatval($text) > 2147483647) {
            throw new CreditException($label . " must be a non-negative whole number.");
        }
        return intval($text);
    }

    private function nullableDate($value) {
        $value = trim(strval($value));
        return $value === "" ? null : $value;
    }

    private function requiredProgramId($body) {
        $id = intval(CreditServiceSupport::value($body, "program_id", 0));
        if ($id < 1 || !$this->database()->one("SELECT id FROM davvag_credit_program WHERE id=? LIMIT 1", "i", array($id))) {
            throw new CreditException("A valid credit program is required.");
        }
        return $id;
    }

    private function product($id) {
        if (intval($id) < 1) {
            return null;
        }
        return $this->database()->one(
            "SELECT itemid,name,caption,price,currencycode,imgurl,catogory,uom,status FROM products WHERE itemid=? LIMIT 1",
            "i",
            array(intval($id))
        );
    }

    private function catalogProduct($product) {
        $item = new \stdClass();
        $item->product_id = intval($product->itemid);
        $item->product_code = strval($product->itemid);
        $item->product_title = isset($product->name) ? $product->name : "";
        $item->product_price = isset($product->price) ? $product->price : 0;
        $item->product_currency_code = isset($product->currencycode) ? $product->currencycode : "";
        $item->caption = isset($product->caption) ? trim(strip_tags($product->caption)) : "";
        $item->category = isset($product->catogory) ? $product->catogory : "";
        $item->uom = isset($product->uom) ? $product->uom : "";
        $item->status = isset($product->status) ? $product->status : "";
        $item->image = "";
        if (!empty($product->imgurl)) {
            $item->image = "components/dock/soss-uploader/service/get/products/" . intval($product->itemid) . "-" . $product->imgurl;
        }
        return $item;
    }

    private function deleteConfiguration($table, $id, $references) {
        $id = intval($id);
        if ($id < 1) {
            throw new CreditException("A valid record id is required.");
        }
        $db = $this->database();
        $record = $db->one("SELECT * FROM `" . $table . "` WHERE id=? LIMIT 1", "i", array($id));
        if (!$record) {
            throw new CreditException("The configuration record was not found.");
        }
        $used = 0;
        foreach ($references as $reference) {
            $count = $db->one(
                "SELECT COUNT(*) amount FROM `" . $reference[0] . "` WHERE `" . $reference[1] . "`=?",
                "i",
                array($id)
            );
            $used += intval($count->amount);
        }
        if ($used > 0) {
            $db->updateById($table, $id, array("status" => "DELETED"));
            return (object) array(
                "id" => $id,
                "deleted" => true,
                "archived" => true,
                "message" => "The record was removed from administration and retained for audit history."
            );
        }
        $db->run("DELETE FROM `" . $table . "` WHERE id=?", "i", array($id));
        return (object) array(
            "id" => $id,
            "deleted" => true,
            "archived" => false,
            "message" => "The unused record was deleted."
        );
    }

    public function postBootstrap($req, $res) {
        return $this->call($res, function () {
            $db = $this->database();
            $currency = new CreditCurrencyAdapter();
            return array(
                "programs" => $db->all("SELECT * FROM davvag_credit_program ORDER BY id"),
                "packages" => $db->all("SELECT p.*,product.name mapped_product_name FROM davvag_credit_package p LEFT JOIN products product ON product.itemid=p.product_id WHERE COALESCE(p.status,'')<>'DELETED' ORDER BY p.sort_order,p.id"),
                "rewards" => $db->all("SELECT * FROM davvag_credit_reward_rule WHERE COALESCE(status,'')<>'DELETED' ORDER BY id"),
                "campaigns" => $db->all("SELECT * FROM davvag_credit_coupon_campaign WHERE COALESCE(status,'')<>'DELETED' ORDER BY id"),
                "couponCodes" => $db->all("SELECT code.*,campaign.name campaign_name,campaign.campaign_code FROM davvag_credit_coupon_code code JOIN davvag_credit_coupon_campaign campaign ON campaign.id=code.campaign_id WHERE COALESCE(campaign.status,'')<>'DELETED' ORDER BY code.id DESC LIMIT 200"),
                "wallets" => $db->all("SELECT * FROM davvag_credit_wallet WHERE wallet_type='USER_WALLET' ORDER BY id DESC LIMIT 100"),
                "currencies" => $currency->active(),
                "defaultCurrency" => $currency->defaultCurrency()
            );
        });
    }

    public function postProductCatalog($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $search = strtolower(trim(strval(CreditServiceSupport::value($body, "search", ""))));
            $limit = max(1, min(200, intval(CreditServiceSupport::value($body, "limit", 100))));
            $db = $this->database();
            if ($search === "") {
                $rows = $db->all("SELECT itemid,name,caption,keywords,price,currencycode,imgurl,catogory,uom,status FROM products ORDER BY itemid DESC LIMIT ?", "i", array($limit));
            } else {
                $like = "%" . $search . "%";
                $rows = $db->all(
                    "SELECT itemid,name,caption,keywords,price,currencycode,imgurl,catogory,uom,status FROM products WHERE CAST(itemid AS CHAR)=? OR LOWER(COALESCE(name,'')) LIKE ? OR LOWER(COALESCE(caption,'')) LIKE ? OR LOWER(COALESCE(keywords,'')) LIKE ? OR LOWER(COALESCE(catogory,'')) LIKE ? OR LOWER(COALESCE(uom,'')) LIKE ? ORDER BY itemid DESC LIMIT ?",
                    "ssssssi",
                    array($search, $like, $like, $like, $like, $like, $limit)
                );
            }
            $items = array();
            foreach ($rows as $row) {
                $items[] = $this->catalogProduct($row);
            }
            return $items;
        });
    }

    public function postSaveProgram($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            if (isset($body->precision) && intval($body->precision) !== 0) {
                throw new CreditException("Credit precision must remain zero.");
            }
            if (isset($body->allow_negative_balance) && CreditRules::truthy($body->allow_negative_balance)) {
                throw new CreditException("Negative user balances are not supported.");
            }
            return $this->save("davvag_credit_program", $body, array("code", "name", "description", "symbol", "precision", "timezone", "allow_negative_balance", "spending_policy", "purchase_available", "reward_available", "coupon_available", "purchased_credits_expire", "default_reward_expiry_days", "status"));
        });
    }

    public function postSavePackage($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $body->program_id = $this->requiredProgramId($body);
            $body->package_code = CreditRules::code(CreditServiceSupport::value($body, "package_code", ""), 60);
            $body->title = CreditRules::text(CreditServiceSupport::value($body, "title", ""), "Package title", 180);
            $body->credit_amount = CreditRules::amount(CreditServiceSupport::value($body, "credit_amount", 0));
            $body->bonus_credit_amount = $this->nonNegativeInteger(CreditServiceSupport::value($body, "bonus_credit_amount", 0), "Bonus credits");
            $body->price_minor = $this->nonNegativeInteger(CreditServiceSupport::value($body, "price_minor", -1), "Package price");
            $configured = (new CreditCurrencyAdapter())->requireActive(CreditServiceSupport::value($body, "currency", ""));
            $body->currency = $configured->code;
            $body->product_id = intval(CreditServiceSupport::value($body, "product_id", 0));
            if ($body->product_id > 0 && !$this->product($body->product_id)) {
                throw new CreditException("The mapped DAVVAG product was not found.");
            }
            if (!isset($body->status) || trim(strval($body->status)) === "") {
                $body->status = "ACTIVE";
            }
            $body->active_from = $this->nullableDate(CreditServiceSupport::value($body, "active_from", ""));
            $body->active_until = $this->nullableDate(CreditServiceSupport::value($body, "active_until", ""));
            return $this->save("davvag_credit_package", $body, array("program_id", "package_code", "title", "description", "credit_amount", "bonus_credit_amount", "price_minor", "currency", "payment_channel", "provider_product_id", "product_id", "purchase_limit_per_profile", "first_purchase_only", "active_from", "active_until", "sort_order", "status"));
        });
    }

    public function postDeletePackage($req, $res) {
        return $this->call($res, function () use ($req) {
            return $this->deleteConfiguration("davvag_credit_package", CreditServiceSupport::value($this->body($req), "id", 0), array(
                array("davvag_credit_purchase_order", "package_id")
            ));
        });
    }

    public function postSaveRewardRule($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $body->program_id = $this->requiredProgramId($body);
            $body->rule_code = CreditRules::code(CreditServiceSupport::value($body, "rule_code", ""), 60);
            $body->title = CreditRules::text(CreditServiceSupport::value($body, "title", ""), "Reward title", 180);
            $body->cadence = strtoupper(trim(strval(CreditServiceSupport::value($body, "cadence", ""))));
            if (!in_array($body->cadence, array("DAILY", "WEEKLY", "MONTHLY"), true)) {
                throw new CreditException("Reward cadence must be daily, weekly, or monthly.");
            }
            $body->credit_amount = CreditRules::amount(CreditServiceSupport::value($body, "credit_amount", 0));
            $body->expiry_days = $this->nonNegativeInteger(CreditServiceSupport::value($body, "expiry_days", 0), "Reward expiry days");
            if (!isset($body->status) || trim(strval($body->status)) === "") {
                $body->status = "ACTIVE";
            }
            $body->active_from = $this->nullableDate(CreditServiceSupport::value($body, "active_from", ""));
            $body->active_until = $this->nullableDate(CreditServiceSupport::value($body, "active_until", ""));
            return $this->save("davvag_credit_reward_rule", $body, array("program_id", "rule_code", "title", "cadence", "award_mode", "credit_amount", "timezone", "week_start_day", "month_claim_day", "claim_window_hours", "eligibility_json", "expiry_days", "active_from", "active_until", "status"));
        });
    }

    public function postDeleteRewardRule($req, $res) {
        return $this->call($res, function () use ($req) {
            return $this->deleteConfiguration("davvag_credit_reward_rule", CreditServiceSupport::value($this->body($req), "id", 0), array(
                array("davvag_credit_reward_claim", "rule_id")
            ));
        });
    }

    public function postSaveCouponCampaign($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $body->program_id = $this->requiredProgramId($body);
            $body->campaign_code = CreditRules::code(CreditServiceSupport::value($body, "campaign_code", ""), 60);
            $body->name = CreditRules::text(CreditServiceSupport::value($body, "name", ""), "Campaign name", 180);
            $body->credit_amount = CreditRules::amount(CreditServiceSupport::value($body, "credit_amount", 0));
            $body->total_redemption_limit = $this->nonNegativeInteger(CreditServiceSupport::value($body, "total_redemption_limit", 0), "Total redemption limit");
            $body->per_profile_limit = $this->nonNegativeInteger(CreditServiceSupport::value($body, "per_profile_limit", 1), "Per-profile limit");
            if ($body->per_profile_limit < 1) {
                throw new CreditException("Per-profile limit must be at least one.");
            }
            $body->minimum_account_age_days = $this->nonNegativeInteger(CreditServiceSupport::value($body, "minimum_account_age_days", 0), "Minimum account age days");
            $body->credit_expiry_days = $this->nonNegativeInteger(CreditServiceSupport::value($body, "credit_expiry_days", 0), "Coupon credit expiry days");
            if (!isset($body->status) || trim(strval($body->status)) === "") {
                $body->status = "ACTIVE";
            }
            $body->active_from = $this->nullableDate(CreditServiceSupport::value($body, "active_from", ""));
            $body->active_until = $this->nullableDate(CreditServiceSupport::value($body, "active_until", ""));
            return $this->save("davvag_credit_coupon_campaign", $body, array("program_id", "campaign_code", "coupon_type", "name", "description", "credit_amount", "total_redemption_limit", "per_profile_limit", "first_time_only", "minimum_account_age_days", "eligible_group_ids_json", "active_from", "active_until", "credit_expiry_days", "status"));
        });
    }

    public function postDeleteCouponCampaign($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $id = intval(CreditServiceSupport::value($body, "id", 0));
            $result = $this->deleteConfiguration("davvag_credit_coupon_campaign", $id, array(
                array("davvag_credit_coupon_code", "campaign_id"),
                array("davvag_credit_coupon_redemption", "campaign_id")
            ));
            if ($result->archived) {
                $this->database()->run("UPDATE davvag_credit_coupon_code SET status='DISABLED' WHERE campaign_id=? AND status='ACTIVE'", "i", array($id));
            }
            return $result;
        });
    }

    public function postDeleteCouponCode($req, $res) {
        return $this->call($res, function () use ($req) {
            $id = intval(CreditServiceSupport::value($this->body($req), "id", 0));
            if ($id < 1) {
                throw new CreditException("A valid coupon id is required.");
            }
            $db = $this->database();
            $code = $db->one("SELECT * FROM davvag_credit_coupon_code WHERE id=? LIMIT 1", "i", array($id));
            if (!$code) {
                throw new CreditException("The coupon was not found.");
            }
            $used = $db->one("SELECT COUNT(*) amount FROM davvag_credit_coupon_redemption WHERE coupon_code_id=?", "i", array($id));
            if (intval($used->amount) > 0) {
                $db->updateById("davvag_credit_coupon_code", $id, array("status" => "DISABLED"));
                return (object) array("id" => $id, "deleted" => true, "archived" => true, "message" => "The redeemed coupon was disabled and retained for audit history.");
            }
            $db->run("DELETE FROM davvag_credit_coupon_code WHERE id=?", "i", array($id));
            return (object) array("id" => $id, "deleted" => true, "archived" => false, "message" => "The unused coupon was deleted.");
        });
    }

    public function postGenerateCoupons($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            return array(
                "codes" => (new CreditCouponService())->generate(
                    CreditServiceSupport::value($body, "campaign_id", 0),
                    CreditServiceSupport::value($body, "count", 1),
                    CreditServiceSupport::value($body, "maximum_redemptions", 1),
                    CreditServiceSupport::value($body, "assigned_profile_id", 0),
                    CreditServiceSupport::value($body, "expires_at", null)
                ),
                "warning" => "Plain coupon codes are returned once. Store them securely."
            );
        });
    }

    public function postWallets($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $limit = max(1, min(200, intval(CreditServiceSupport::value($body, "limit", 100))));
            return $this->database()->all("SELECT * FROM davvag_credit_wallet WHERE wallet_type='USER_WALLET' ORDER BY id DESC LIMIT ?", "i", array($limit));
        });
    }

    public function postLedger($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $limit = max(1, min(200, intval(CreditServiceSupport::value($body, "limit", 100))));
            return $this->database()->all("SELECT t.*,COUNT(e.id) entry_count FROM davvag_credit_transaction t LEFT JOIN davvag_credit_entry e ON e.transaction_id=t.id GROUP BY t.id ORDER BY t.id DESC LIMIT ?", "i", array($limit));
        });
    }

    public function postGrant($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            return (new CreditLedgerService())->credit(
                CreditServiceSupport::value($body, "profile_id", 0),
                CreditServiceSupport::value($body, "amount", 0),
                CreditServiceSupport::context($body, "davvag-credit-points-admin", "admin-adjustment", CreditServiceSupport::value($body, "reference_id", "grant"), CreditServiceSupport::value($body, "description", "Administrator credit grant")),
                "ADMIN",
                CreditServiceSupport::value($body, "expires_at", null)
            );
        });
    }

    public function postDebit($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            return (new CreditLedgerService())->debit(
                CreditServiceSupport::value($body, "profile_id", 0),
                CreditServiceSupport::value($body, "amount", 0),
                CreditServiceSupport::context($body, "davvag-credit-points-admin", "admin-adjustment", CreditServiceSupport::value($body, "reference_id", "debit"), CreditServiceSupport::value($body, "description", "Administrator credit debit"))
            );
        });
    }

    public function postSuspendWallet($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            $db = $this->database();
            $id = intval(CreditServiceSupport::value($body, "wallet_id", 0));
            $status = CreditRules::truthy(CreditServiceSupport::value($body, "suspended", true)) ? "SUSPENDED" : "ACTIVE";
            $db->updateById("davvag_credit_wallet", $id, array("status" => $status, "suspended_reason" => CreditServiceSupport::value($body, "reason", "")));
            return $db->one("SELECT * FROM davvag_credit_wallet WHERE id=?", "i", array($id));
        });
    }

    public function postReverse($req, $res) {
        return $this->call($res, function () use ($req) {
            $body = $this->body($req);
            return (new CreditLedgerService())->reverse(
                CreditServiceSupport::value($body, "transaction_id", 0),
                CreditServiceSupport::context($body, "davvag-credit-points-admin", "credit-reversal", CreditServiceSupport::value($body, "transaction_id", 0), CreditServiceSupport::value($body, "description", "Administrator reversal"))
            );
        });
    }

    public function postReconcile($req, $res) {
        return $this->call($res, function () use ($req) {
            return (new CreditLedgerService())->reconcile(CreditServiceSupport::value($this->body($req), "wallet_id", 0));
        });
    }

    public function postProcessExpirations($req, $res) {
        return $this->call($res, function () use ($req) {
            return (new CreditLedgerService())->expireDueLots(CreditServiceSupport::value($this->body($req), "limit", 100));
        });
    }
}
?>
