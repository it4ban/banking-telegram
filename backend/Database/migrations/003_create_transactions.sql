CREATE TYPE transaction_state AS ENUM (
    'approved',
    'declined',
    'pending'
);

CREATE TABLE transactions (
    id uuid PRIMARY KEY DEFAULT uuidv4(),
    card_id uuid NOT NULL,
    lithic_transaction_token text NOT NULL,
    amount numeric(10, 2) NOT NULL DEFAULT 0,
    merchant varchar(255) DEFAULT 'unknown',
    status transaction_state NOT NULL DEFAULT 'pending',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT chk_cards_amount
    CHECK (amount >= 0),

    CONSTRAINT fk_transactions_card
    FOREIGN KEY (card_id)
    REFERENCES cards (id)
    ON DELETE CASCADE
);

CREATE INDEX idx_transactions_card_id
ON transactions (card_id);
