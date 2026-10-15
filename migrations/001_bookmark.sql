-- A bookmark exists only as its own row: no status, nothing to carry once
-- the account removes it - a repeated toggle just inserts or deletes this
-- one row. "kind" names what target_id points at ("offer" or "provider"),
-- the two public pages this addon can mark - there is no foreign key to
-- offer/provider, since a bookmark survives whatever happens to its target
-- and Bookmarks itself filters a target that is since gone.

CREATE TABLE x_bookmark_bookmark (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    kind VARCHAR(10) NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_bookmark_bookmark (account_id, kind, target_id),
    KEY idx_x_bookmark_bookmark_account (account_id, kind),
    CONSTRAINT fk_x_bookmark_bookmark_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
