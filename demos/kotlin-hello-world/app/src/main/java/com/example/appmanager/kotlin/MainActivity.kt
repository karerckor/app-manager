package com.example.appmanager.kotlin

import android.app.Activity
import android.os.Bundle
import android.view.Gravity
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView

class MainActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val layout = LinearLayout(this).apply {
            gravity = Gravity.CENTER
            orientation = LinearLayout.VERTICAL
            setPadding(48, 48, 48, 48)
        }
        val splashId = resources.getIdentifier("app_manager_splash", "drawable", packageName)
        if (splashId != 0) {
            layout.addView(ImageView(this).apply {
                setImageResource(splashId)
                adjustViewBounds = true
                maxHeight = 480
            })
        }
        layout.addView(TextView(this).apply {
            text = getString(R.string.app_name)
            textSize = 28f
            gravity = Gravity.CENTER
        })
        layout.addView(TextView(this).apply {
            text = packageName
            textSize = 14f
            gravity = Gravity.CENTER
        })

        setContentView(layout)
    }
}
