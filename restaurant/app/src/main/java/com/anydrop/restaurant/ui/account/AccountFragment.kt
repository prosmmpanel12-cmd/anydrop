package com.anydrop.restaurant.ui.account

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import com.anydrop.restaurant.data.TokenManager
import com.anydrop.restaurant.databinding.FragmentAccountBinding
import com.anydrop.restaurant.ui.login.LoginActivity

/**
 * Account tab — minimal placeholder for the bottom-nav shell (§10 item
 * 2), holding just the restaurant name and Logout (moved here from the
 * old Dashboard top bar per docs/restorent/20 §3's "still not covered"
 * note — this is where it lands). The real §7 content — profile edit
 * form, bank/payout section, notification toggle — is §10 item 5.
 */
class AccountFragment : Fragment() {

    private var _binding: FragmentAccountBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentAccountBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val tokenManager = TokenManager(requireContext())
        binding.restaurantNameText.text = tokenManager.getRestaurantName().orEmpty()
        binding.btnLogout.setOnClickListener {
            tokenManager.clear()
            startActivity(
                Intent(requireContext(), LoginActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
            )
            requireActivity().finish()
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
